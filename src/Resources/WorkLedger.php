<?php
declare(strict_types=1);
namespace WPClamAV\Resources;
use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\Files;

/** Bounded rolling account CPU, scan reserve and global scan pacing. */
final class WorkLedger
{
    private const PRECISION = 60;
    private const WINDOWS = ['hourly' => 3600, 'daily' => 86400, 'monthly' => 2678400];
    private const SCAN_FRACTION = 0.8;
    private \Closure $clock;
    private string $path;
    public function __construct(private FileSubmissionStore $store, private array $budgets, ?callable $clock = null)
    {
        if (PHP_INT_SIZE < 8) { throw new \RuntimeException('CPU accounting requires64-bit PHP.'); }
        $this->clock = $clock === null ? static fn(): int => time() : \Closure::fromCallable($clock);
        $this->path = $store->directory() . '/cpu-budget.json';
    }
    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0 || $now > 0xffffffff - self::PRECISION) { throw new \RuntimeException('Unsupported CPU accounting clock.'); }
        return $now;
    }
    private function allowance(string $period): ?float
    {
        $value = $this->budgets['cpu_' . $period . '_seconds'] ?? null;
        if ($value === null || $value === '') { return null; }
        if (!is_numeric($value) || !is_finite((float) $value) || $value < 0 || $value > 0xffffffff / 1000) {
            throw new \InvalidArgumentException('CPU allocation is outside the supported finite range: ' . $period);
        }
        return (float) $value;
    }
    private function read(): array
    {
        if (!file_exists($this->path)) { return ['schema' => 2, 'precision_seconds' => 60, 'buckets' => [], 'reservations' => [], 'conservative_settlements' => 0, 'scan_next_at' => 0.0]; }
        $state = Files::readJson($this->path); $schema = $state['schema'] ?? 0;
        if (!in_array($schema, [1,2], true) || ($state['precision_seconds'] ?? null) !== 60
            || !is_string($state['packed_buckets'] ?? null) || !is_array($state['reservations'] ?? null) || count($state['reservations']) > 1024) {
            throw new \RuntimeException('CPU accounting state is invalid; work remains held.');
        }
        $width = $schema === 1 ? 8 : 12; $raw = base64_decode($state['packed_buckets'], true);
        if ($raw === false || strlen($raw) % $width !== 0 || strlen($raw) > $width * (intdiv(2678400,60)+2)) { throw new \RuntimeException('Invalid CPU buckets.'); }
        $buckets = []; $previous = -1;
        for ($offset=0; $offset<strlen($raw); $offset+=$width) {
            $v = unpack($schema === 1 ? 'Vstart/Vtotal' : 'Vstart/Vtotal/Vscan', substr($raw,$offset,$width));
            if ($v['start'] % 60 !== 0 || $v['start'] <= $previous || ($v['scan'] ?? 0) > $v['total']) { throw new \RuntimeException('Invalid CPU bucket ordering/counts.'); }
            // Existing unclassified work conservatively occupies the scan slice too.
            $buckets[$v['start']] = [$v['total'], $schema === 1 ? $v['total'] : $v['scan']]; $previous=$v['start'];
        }
        foreach ($state['reservations'] as $token => &$r) {
            if (preg_match('/\A[a-f0-9]{32}\z/D', (string)$token)!==1 || !is_array($r)
                || !is_int($r['cpu_milliseconds']??null) || $r['cpu_milliseconds']<1 || $r['cpu_milliseconds']>0xffffffff
                || !is_int($r['deadline']??null) || $r['deadline']<0 || !is_string($r['job']??null)) { throw new \RuntimeException('Invalid CPU reservation.'); }
            if ($schema === 1) { $r['class']='scan'; }
            if (!in_array($r['class']??null,['scan','maintenance'],true)) { throw new \RuntimeException('Invalid CPU work class.'); }
            foreach (['pace_rate','pace_base'] as $key) {
                if (isset($r[$key]) && (!is_numeric($r[$key]) || !is_finite((float)$r[$key]) || $r[$key]<0)) { throw new \RuntimeException('Invalid pacing reservation.'); }
            }
        }
        unset($r);
        if ($schema===2 && (!is_numeric($state['scan_next_at']??null) || !is_finite((float)$state['scan_next_at']) || $state['scan_next_at']<0 || $state['scan_next_at']>0xffffffff-60)) { throw new \RuntimeException('Invalid scan pacing state.'); }
        $state['scan_next_at']=(float)($state['scan_next_at']??0); $state['schema']=2; $state['buckets']=$buckets;
        return $state;
    }
    private function write(array $state): void
    {
        ksort($state['buckets'], SORT_NUMERIC); $packed='';
        foreach ($state['buckets'] as $at => [$total,$scan]) { $packed.=pack('VVV',$at,$total,$scan); }
        unset($state['buckets']); $state['packed_buckets']=base64_encode($packed); $state['observed_at']=$this->now();
        Files::json($this->path,$state);
    }
    private function add(array &$s,int $at,int $ms,string $class): void
    {
        $key=intdiv($at,60)*60; $v=$s['buckets'][$key]??[0,0]; $v[0]+=$ms;
        if ($class==='scan') { $v[1]+=$ms; }
        if ($v[0]>0xffffffff || $v[1]>$v[0]) { throw new \RuntimeException('CPU bucket overflow; work remains held.'); }
        $s['buckets'][$key]=$v;
    }
    private function settle(array &$s,int $now): bool
    {
        $changed=false;
        foreach ($s['reservations'] as $token=>$r) {
            if ($r['deadline']<=$now) { $this->add($s,$r['deadline'],$r['cpu_milliseconds'],$r['class']); unset($s['reservations'][$token]); ++$s['conservative_settlements']; $changed=true; }
        }
        foreach ($s['buckets'] as $at=>$v) { if ($at+60<=$now-2678400) { unset($s['buckets'][$at]); $changed=true; } }
        return $changed;
    }
    private function report(array $s,int $now): array
    {
        $pending=[0,0]; foreach ($s['reservations'] as $r) { $pending[0]+=$r['cpu_milliseconds']; if ($r['class']==='scan') { $pending[1]+=$r['cpu_milliseconds']; } }
        $windows=[]; $warnings=[]; $rates=[];
        foreach (self::WINDOWS as $period=>$duration) {
            $used=$pending;
            foreach ($s['buckets'] as $at=>$v) { if ($at+60>$now-$duration) { $used[0]+=$v[0]; $used[1]+=$v[1]; } }
            $allocation=$this->allowance($period); $scan=$allocation===null?null:$allocation*self::SCAN_FRACTION;
            if ($allocation===null) { $warnings[]='cpu_'.$period.'_seconds is unknown; scanner work remains held.'; } else { $rates[]=$scan/$duration; }
            $windows[$period]=['window_seconds'=>$duration,'allocation_seconds'=>$allocation,'used_seconds'=>$used[0]/1000.0,
                'remaining_seconds'=>$allocation===null?null:max(0,$allocation-$used[0]/1000.0),
                'scan_allocation_seconds'=>$scan,'scan_used_seconds'=>$used[1]/1000.0,'scan_remaining_seconds'=>$scan===null?null:max(0,$scan-$used[1]/1000.0)];
        }
        return ['windows'=>$windows,'precision_seconds'=>60,'pending_reservations'=>count($s['reservations']),
            'conservative_settlements'=>$s['conservative_settlements'],'scan_next_at'=>(float)$s['scan_next_at'],
            'scan_rate_seconds_per_second'=>count($rates)===3?min($rates):null,'maintenance_reserve_fraction'=>0.2,
            'minimum_scan_charge_seconds'=>1,'warnings'=>$warnings,'observed_at'=>$now];
    }
    public function remaining(): array
    {
        return $this->store->withLock('cpu-budget',function():array { $now=$this->now(); $s=$this->read(); if($this->settle($s,$now)){$this->write($s);} return $this->report($s,$now); });
    }
    public function reserve(string $job,float $cpuSeconds,float $wallSeconds,string $class='maintenance'): string
    {
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D',$job)!==1 || !in_array($class,['scan','maintenance'],true)
            || !is_finite($cpuSeconds) || $cpuSeconds<=0 || $cpuSeconds>0xffffffff/1000 || !is_finite($wallSeconds) || $wallSeconds<=0 || $wallSeconds>2678400) { throw new \InvalidArgumentException('Invalid bounded CPU work request.'); }
        return $this->store->withLock('cpu-budget',function()use($job,$cpuSeconds,$wallSeconds,$class):string {
            $now=$this->now(); $s=$this->read(); $this->settle($s,$now); $report=$this->report($s,$now);
            $ms=(int)ceil(max($class==='scan'?max(1,$wallSeconds):0,$cpuSeconds)*1000);
            foreach ($report['windows'] as $period=>$window) {
                if ($window['remaining_seconds']===null) { throw new ResourceDeferred('cpu_allocation_unknown',$now+60); }
                if ($ms/1000>$window['remaining_seconds'] || $class==='scan' && $ms/1000>$window['scan_remaining_seconds']) { throw new ResourceDeferred('cpu_'.$period.'_exhausted',$now+60); }
            }
            $rate=$report['scan_rate_seconds_per_second'];
            if ($class==='scan' && $s['scan_next_at']>$now) { throw new ResourceDeferred('scan_pacing',min(0xffffffff-60,(int)ceil($s['scan_next_at']))); }
            if(count($s['reservations'])>=128 || $now+ceil($wallSeconds)>0xffffffff-60) { throw new ResourceDeferred('cpu_reservation_capacity',$now+60); }
            $token=bin2hex(random_bytes(16));
            $r=['job'=>$job,'class'=>$class,'cpu_milliseconds'=>$ms,'created_at'=>$now,'deadline'=>$now+(int)ceil($wallSeconds)];
            if($class==='scan') {
                if($rate===null || $rate<=0) { throw new ResourceDeferred('scan_allocation_exhausted',$now+60); }
                $next=max($now,$s['scan_next_at'])+$ms/1000/$rate;
                if(!is_finite($next) || $next>0xffffffff-60) { throw new ResourceDeferred('scan_pacing_capacity',$now+60); }
                $r['pace_rate']=$rate; $r['pace_base']=max($now,$s['scan_next_at']); $s['scan_next_at']=$next;
            }
            $s['reservations'][$token]=$r; $this->write($s); return $token;
        });
    }
    public function complete(string $token,?float $actualCpuSeconds): void
    {
        if(preg_match('/\A[a-f0-9]{32}\z/D',$token)!==1 || $actualCpuSeconds!==null && (!is_finite($actualCpuSeconds)||$actualCpuSeconds<0||$actualCpuSeconds>0xffffffff/1000)) { throw new \InvalidArgumentException('Invalid CPU completion.'); }
        $this->store->withLock('cpu-budget',function()use($token,$actualCpuSeconds):void {
            $now=$this->now(); $s=$this->read(); $this->settle($s,$now); if(!isset($s['reservations'][$token])){return;}
            $r=$s['reservations'][$token]; $ms=$actualCpuSeconds===null?$r['cpu_milliseconds']:(int)ceil(max($r['class']==='scan'?1:0,$actualCpuSeconds)*1000);
            $this->add($s,$now,$ms,$r['class']); unset($s['reservations'][$token]);
            if($r['class']==='scan' && isset($r['pace_rate']) && $r['pace_rate']>0) {
                $s['scan_next_at']=max($r['pace_base'],$s['scan_next_at']+($ms-$r['cpu_milliseconds'])/1000/$r['pace_rate']);
            }
            $this->write($s);
        });
    }
}

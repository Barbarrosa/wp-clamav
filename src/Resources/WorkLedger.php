<?php
declare(strict_types=1);
namespace WPClamAV\Resources;

use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\Files;

/** Conservative rolling CPU reservations. Operational counters are not submission history. */
final class WorkLedger
{
    private const PRECISION = 60;
    private const WINDOWS = ['hourly' => 3600, 'daily' => 86400, 'monthly' => 2678400];
    private \Closure $clock;
    private string $path;

    public function __construct(private FileSubmissionStore $store, private array $budgets, ?callable $clock = null)
    {
        if (PHP_INT_SIZE < 8) { throw new \RuntimeException('CPU accounting requires a 64-bit PHP runtime.'); }
        $this->clock = $clock === null ? static fn(): int => time() : \Closure::fromCallable($clock);
        $this->path = $store->directory() . '/cpu-budget.json';
    }

    private function now(): int
    {
        $now = (int) ($this->clock)();
        if ($now < 0 || $now > 0xffffffff - self::PRECISION) { throw new \RuntimeException('Unsupported CPU accounting clock.'); }
        return $now;
    }

    private function allowance(string $period): ?float
    {
        $value = $this->budgets['cpu_' . $period . '_seconds'] ?? null;
        if ($value === null || $value === '') { return null; }
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            throw new \InvalidArgumentException('Invalid CPU allocation for ' . $period . '.');
        }
        return (float) $value;
    }

    private function read(): array
    {
        if (!file_exists($this->path)) { return ['schema' => 1, 'precision_seconds' => self::PRECISION, 'buckets' => [], 'reservations' => [], 'conservative_settlements' => 0]; }
        $state = Files::readJson($this->path);
        if (($state['schema'] ?? null) !== 1 || ($state['precision_seconds'] ?? null) !== self::PRECISION
            || !is_string($state['packed_buckets'] ?? null) || !is_array($state['reservations'] ?? null)
            || count($state['reservations']) > 1024) { throw new \RuntimeException('CPU accounting state is invalid; work remains held.'); }
        $packed = base64_decode($state['packed_buckets'], true);
        if ($packed === false || strlen($packed) % 8 !== 0 || strlen($packed) > 8 * (intdiv(self::WINDOWS['monthly'], self::PRECISION) + 2)) {
            throw new \RuntimeException('CPU accounting buckets are invalid.');
        }
        $buckets = []; $previous = -1;
        for ($offset = 0; $offset < strlen($packed); $offset += 8) {
            $bucket = unpack('Vstart/Vmilliseconds', substr($packed, $offset, 8));
            if ($bucket['start'] % self::PRECISION !== 0 || $bucket['start'] <= $previous) { throw new \RuntimeException('CPU buckets are out of order.'); }
            $buckets[$bucket['start']] = $bucket['milliseconds']; $previous = $bucket['start'];
        }
        foreach ($state['reservations'] as $token => $reservation) {
            if (preg_match('/\A[a-f0-9]{32}\z/D', (string) $token) !== 1 || !is_array($reservation)
                || !is_int($reservation['cpu_milliseconds'] ?? null) || $reservation['cpu_milliseconds'] < 1
                || !is_int($reservation['deadline'] ?? null) || !is_string($reservation['job'] ?? null)) {
                throw new \RuntimeException('CPU reservation state is invalid.');
            }
        }
        $state['buckets'] = $buckets;
        return $state;
    }

    private function write(array $state): void
    {
        ksort($state['buckets'], SORT_NUMERIC);
        $packed = '';
        foreach ($state['buckets'] as $start => $milliseconds) { $packed .= pack('VV', $start, $milliseconds); }
        unset($state['buckets']);
        $state['packed_buckets'] = base64_encode($packed);
        $state['observed_at'] = $this->now();
        Files::json($this->path, $state);
    }

    private function addCpu(array &$state, int $at, int $milliseconds): void
    {
        $bucket = intdiv($at, self::PRECISION) * self::PRECISION;
        $sum = ($state['buckets'][$bucket] ?? 0) + $milliseconds;
        if ($sum > 0xffffffff) { throw new \RuntimeException('CPU accounting bucket overflow; work remains held.'); }
        $state['buckets'][$bucket] = $sum;
    }

    private function settle(array &$state, int $now): bool
    {
        $changed = false;
        foreach ($state['reservations'] as $token => $reservation) {
            if ($reservation['deadline'] <= $now) {
                $this->addCpu($state, $reservation['deadline'], $reservation['cpu_milliseconds']);
                unset($state['reservations'][$token]);
                $state['conservative_settlements'] = (int) ($state['conservative_settlements'] ?? 0) + 1;
                $changed = true;
            }
        }
        foreach ($state['buckets'] as $start => $value) {
            if ($start + self::PRECISION <= $now - self::WINDOWS['monthly']) { unset($state['buckets'][$start]); $changed = true; }
        }
        return $changed;
    }

    private function report(array $state, int $now): array
    {
        $active = array_sum(array_column($state['reservations'], 'cpu_milliseconds'));
        $windows = []; $warnings = [];
        foreach (self::WINDOWS as $period => $duration) {
            $used = $active;
            foreach ($state['buckets'] as $start => $milliseconds) {
                // Include the entire minute if any part can overlap the rolling window.
                if ($start + self::PRECISION > $now - $duration) { $used += $milliseconds; }
            }
            $allocation = $this->allowance($period);
            if ($allocation === null) { $warnings[] = 'cpu_' . $period . '_seconds is unknown; it is not a provider entitlement.'; }
            $windows[$period] = ['window_seconds' => $duration, 'allocation_seconds' => $allocation,
                'used_seconds' => $used / 1000.0, 'remaining_seconds' => $allocation === null ? null : max(0.0, $allocation - $used / 1000.0)];
        }
        return ['windows' => $windows, 'precision_seconds' => self::PRECISION,
            'pending_reservations' => count($state['reservations']), 'conservative_settlements' => (int) ($state['conservative_settlements'] ?? 0),
            'warnings' => $warnings, 'observed_at' => $now];
    }

    public function remaining(): array
    {
        return $this->store->withLock('cpu-budget', function (): array {
            $now = $this->now(); $state = $this->read();
            if ($this->settle($state, $now)) { $this->write($state); }
            return $this->report($state, $now);
        });
    }

    public function reserve(string $job, float $cpuSeconds, float $wallSeconds): string
    {
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $job) !== 1 || !is_finite($cpuSeconds) || $cpuSeconds <= 0
            || !is_finite($wallSeconds) || $wallSeconds <= 0 || $cpuSeconds > intdiv(PHP_INT_MAX, 1000)) {
            throw new \InvalidArgumentException('Invalid bounded CPU work request.');
        }
        return $this->store->withLock('cpu-budget', function () use ($job, $cpuSeconds, $wallSeconds): string {
            $now = $this->now(); $state = $this->read();
            $this->settle($state, $now);
            $milliseconds = (int) ceil($cpuSeconds * 1000);
            foreach ($this->report($state, $now)['windows'] as $period => $window) {
                if ($window['remaining_seconds'] === null || $milliseconds / 1000 > $window['remaining_seconds']) {
                    throw new \RuntimeException('Native work held: the ' . $period . ' CPU allocation is unknown or exhausted.');
                }
            }
            if (count($state['reservations']) >= 1024 || $now + ceil($wallSeconds) > 0xffffffff - self::PRECISION) {
                throw new \RuntimeException('CPU reservation capacity is exhausted.');
            }
            $token = bin2hex(random_bytes(16));
            $state['reservations'][$token] = ['job' => $job, 'cpu_milliseconds' => $milliseconds,
                'created_at' => $now, 'deadline' => $now + (int) ceil($wallSeconds)];
            $this->write($state);
            return $token;
        });
    }

    /** Unknown actual CPU is charged at the full reservation; late repeats cannot refund it. */
    public function complete(string $token, ?float $actualCpuSeconds): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1 || $actualCpuSeconds !== null
            && (!is_finite($actualCpuSeconds) || $actualCpuSeconds < 0 || $actualCpuSeconds > intdiv(PHP_INT_MAX, 1000))) {
            throw new \InvalidArgumentException('Invalid CPU completion.');
        }
        $this->store->withLock('cpu-budget', function () use ($token, $actualCpuSeconds): void {
            $now = $this->now(); $state = $this->read();
            $this->settle($state, $now);
            if (!isset($state['reservations'][$token])) { return; }
            $milliseconds = $actualCpuSeconds === null ? $state['reservations'][$token]['cpu_milliseconds'] : (int) ceil($actualCpuSeconds * 1000);
            $this->addCpu($state, $now, $milliseconds);
            unset($state['reservations'][$token]);
            $this->write($state);
        });
    }
}

<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class):void { if(str_starts_with($class,'WPClamAV\\')) { $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,9)).'.php'; if(is_file($path)){require_once $path;} } });
use WPClamAV\Resources\{ResourcePolicy,WorkLedger,ResourceDeferred};
use WPClamAV\Storage\{FileSubmissionStore,Files};
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void {++$checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}};
$reject=static function(callable $call,string $reason)use($check):ResourceDeferred {try{$call();}catch(ResourceDeferred $e){$check($e->reason===$reason,'deferral reason '.$reason);$check($e->retryAt>0,'deferral has retry time');return $e;}throw new RuntimeException('Expected deferral '.$reason);};
$throws=static function(callable $call,string $message)use($check):void {try{$call();}catch(Throwable){$check(true,$message);return;}$check(false,$message);};
$root=sys_get_temp_dir().'/wp-clamav-dispatch-'.bin2hex(random_bytes(8));mkdir($root,0700);
$remove=static function(string $path)use(&$remove):void {if(is_dir($path)&&!is_link($path)){foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS)as$f){$remove($f->getPathname());}rmdir($path);}else{unlink($path);}};
try {
 $unknown=new ResourcePolicy();$reject(fn()=>$unknown->acquireDispatch(),'coordination_unbound');$reject(fn()=>$unknown->beginScan('scan:x',['wall_seconds'=>5]),'coordination_unbound');$reject(fn()=>$unknown->beginNative('update:x',['cpu_seconds'=>1,'wall_seconds'=>5]),'coordination_unbound');
 $store=new FileSubmissionStore($root.'/account');$policy=new ResourcePolicy();$policy->bindStore($store);
 $lease=$policy->acquireDispatch();$reject(fn()=>$policy->acquireDispatch(),'dispatch_busy');
 if(function_exists('pcntl_fork')){
  $pid=pcntl_fork();if($pid===0){try{$policy->acquireDispatch();exit(2);}catch(ResourceDeferred){exit(0);}}pcntl_waitpid($pid,$status);$check(pcntl_wexitstatus($status)===0,'independent fork cannot acquire live account dispatch');$reject(fn()=>$policy->acquireDispatch(),'dispatch_busy');
 }
 $lease->release();$lease->release();$second=$policy->acquireDispatch();unset($second);$third=$policy->acquireDispatch();$third->release();$check(true,'explicit and destructor release are idempotent');
 if(function_exists('pcntl_fork')){
  $held=$policy->acquireDispatch();$pid=pcntl_fork();
  if($pid===0){file_put_contents($root.'/inherited-ready','yes');usleep(250000);exit(0);}
  while(!is_file($root.'/inherited-ready')){usleep(1000);}$held->release();
  $reject(fn()=>$policy->acquireDispatch(),'dispatch_busy');
  pcntl_waitpid($pid,$status);$released=$policy->acquireDispatch();$released->release();
  $check(true,'closing the PHP lease cannot release a live descendant descriptor');
 }
 if(function_exists('pcntl_fork')){
  $pid=pcntl_fork();if($pid===0){$held=$policy->acquireDispatch();file_put_contents($root.'/child-ready','yes');usleep(300000);exit(0);}while(!is_file($root.'/child-ready')){usleep(1000);}$reject(fn()=>$policy->acquireDispatch(),'dispatch_busy');pcntl_waitpid($pid,$status);$after=$policy->acquireDispatch();$after->release();$check(true,'process exit releases OS slot without timeout metadata');
 }
 $state=(object)['now'=>1700000040];$clock=static fn():int=>$state->now;
 $budgets=['cpu_hourly_seconds'=>3600,'cpu_daily_seconds'=>86400,'cpu_monthly_seconds'=>2678400];
 $paceStore=new FileSubmissionStore($root.'/pace');$ledger=new WorkLedger($paceStore,$budgets,$clock);
 $token=$ledger->reserve('scan:one',5,10,'scan');$r=$ledger->remaining();$check($r['windows']['hourly']['scan_used_seconds']===10.0,'scan admission charges max CPU/wall');$check(abs($r['scan_next_at']-$state->now-12.5)<0.001,'pace uses tightest normalized scan window');
 $before=file_get_contents($paceStore->directory().'/cpu-budget.json');$deferred=$reject(fn()=>$ledger->reserve('scan:two',1,1,'scan'),'scan_pacing');$check(file_get_contents($paceStore->directory().'/cpu-budget.json')===$before,'rejected work changes no durable counters');
 $ledger->complete($token,0.01);$r=$ledger->remaining();$check($r['windows']['hourly']['scan_used_seconds']===1.0,'one-second minimum charge prevents free trivial scans');$check(abs($r['scan_next_at']-$state->now-1.25)<0.001,'completion refunds pacing to measured charge with policy floor');
 $state->now+=2;$token=$ledger->reserve('scan:next',5,5,'scan');$debt=$ledger->remaining()['scan_next_at'];$ledger->complete($token,null);$check($ledger->remaining()['scan_next_at']===$debt,'unknown CPU preserves full pacing debt');
 $state->now+=10;$old=$ledger->reserve('scan:older',5,5,'scan');$state->now+=7;$new=$ledger->reserve('scan:newer',5,5,'scan');$next=$ledger->remaining()['scan_next_at'];$ledger->complete($old,0);$check($ledger->remaining()['scan_next_at']===$next,'expired reservation cannot refund prior pacing debt');$ledger->complete($new,1);
 $reserveStore=new FileSubmissionStore($root.'/reserve');$reserve=new WorkLedger($reserveStore,['cpu_hourly_seconds'=>10,'cpu_daily_seconds'=>100,'cpu_monthly_seconds'=>1000],$clock);
 $scan=$reserve->reserve('scan:exhaust',8,8,'scan');$reject(fn()=>$reserve->reserve('scan:blocked',1,1,'scan'),'cpu_hourly_exhausted');$maintenance=$reserve->reserve('update:maintenance',2,2);$check($reserve->remaining()['windows']['hourly']['used_seconds']===10.0,'maintenance retains twenty percent after public scan pool exhaustion');$reserve->complete($scan,null);$reserve->complete($maintenance,null);
 $live=$policy->acquireDispatch();$expiry=new WorkLedger(new FileSubmissionStore($root.'/expiry'),$budgets,$clock);$expiry->reserve('scan:expiry',1,1,'scan');$state->now+=2;$expiry->remaining();$reject(fn()=>$policy->acquireDispatch(),'dispatch_busy');$live->release();$check(true,'expired CPU reservation never releases a live dispatch slot');
 $missing=new WorkLedger(new FileSubmissionStore($root.'/missing'),[],$clock);$reject(fn()=>$missing->reserve('scan:unknown',1,1,'scan'),'cpu_allocation_unknown');
 $legacyStore=new FileSubmissionStore($root.'/legacy');Files::json($legacyStore->directory().'/cpu-budget.json',['schema'=>1,'precision_seconds'=>60,'packed_buckets'=>base64_encode(pack('VV',intdiv($state->now,60)*60,1000)),'reservations'=>[],'conservative_settlements'=>0]);
 $legacy=new WorkLedger($legacyStore,$budgets,$clock);$check($legacy->remaining()['windows']['hourly']['scan_used_seconds']===1.0,'old unclassified CPU conservatively occupies scan slice');
 $obs=['memory_limit'=>['value'=>'128M'],'php_usage'=>['value'=>0]];
 $limits=['native_as_bytes'=>900000000,'native_process_bytes'=>500000000,'native_account_available_bytes'=>400000000,'native_cpu_seconds'=>5,'native_file_bytes'=>10000,'worker_wall_seconds'=>10,'cleanup_seconds'=>1,'measured_native_rss_bytes'=>1];
 $strict=new ResourcePolicy(['budgets'=>$limits],$obs);$check($strict->nativeLimits(false)['as_bytes']===320000000,'AS clamped to80percent of smaller confirmed resident allocation despite historical RSS');
 $absolute=hrtime(true)/1e9+5;
 $scoped=$strict->withDeadline($absolute,fn()=>$strict->nativeLimits(false));$check($scoped['wall_seconds']<=4&&$scoped['wall_seconds']>3.5,'native scoped deadline preserves cleanup reserve');
 $scopedRemote=$strict->withDeadline($absolute,fn()=>$strict->remoteLimits(false));$check($scopedRemote['wall_seconds']<=4&&$scopedRemote['wall_seconds']>3.5,'remote scoped deadline preserves cleanup reserve');
 $nested=$strict->withDeadline($absolute,fn()=>$strict->withDeadline($absolute+100,fn()=>$strict->nativeLimits(false)));$check($nested['wall_seconds']<=4,'nested deadline cannot extend enclosing batch');
 $check($strict->nativeLimits(false)['wall_seconds']===9.0,'deadline context restored after successful callback');
 $throws(fn()=>$strict->withDeadline($absolute,static function():void{throw new RuntimeException('fixture');}),'throwing deadline callback propagated');$check($strict->nativeLimits(false)['wall_seconds']===9.0,'deadline restored after callback exception');
 $reject(fn()=>$strict->withDeadline(hrtime(true)/1e9-1,fn()=>$strict->nativeLimits(false)),'deadline_exhausted');$reject(fn()=>$strict->withDeadline(hrtime(true)/1e9-1,fn()=>$strict->remoteLimits(false)),'deadline_exhausted');
 $throws(fn()=>$strict->withDeadline(INF,static fn()=>null),'nonfinite deadline rejected');
 $injectedState=(object)['now'=>1700000040];$injectedPolicy=new ResourcePolicy(['budgets'=>$budgets+['cleanup_seconds'=>0]],$obs,static fn():int=>$injectedState->now);$injectedPolicy->bindStore(new FileSubmissionStore($root.'/injected-clock'));
 $injectedToken=$injectedPolicy->beginScan('scan:clock',['wall_seconds'=>1]);$injectedPolicy->finishNative($injectedToken,1);$reject(fn()=>$injectedPolicy->beginScan('scan:early',['wall_seconds'=>1]),'scan_pacing');$injectedState->now+=2;$injectedToken=$injectedPolicy->beginScan('scan:advanced',['wall_seconds'=>1]);$injectedPolicy->finishNative($injectedToken,1);$check(true,'constructor-only clock injection advances pacing without wall sleeps');
 foreach(['native_process_bytes','native_account_available_bytes']as$key){$bad=$limits;unset($bad[$key]);$reject(fn()=>(new ResourcePolicy(['budgets'=>$bad],$obs))->nativeLimits(false),'resident_allocation_unknown');}
 $check(ResourcePolicy::bytes((string)PHP_INT_MAX)===PHP_INT_MAX,'largest integer byte value parses exactly');$check(ResourcePolicy::bytes('0001024')===1024,'leading zeros parse exactly');$throws(fn()=>ResourcePolicy::bytes('9223372036854775808'),'integer boundary overflow rejected');$throws(fn()=>ResourcePolicy::bytes('9007199254740992K'),'scaled boundary overflow rejected');$throws(fn()=>(new WorkLedger($store,['cpu_hourly_seconds'=>1e300],$clock))->remaining(),'unrepresentable CPU allowance rejected');
 echo json_encode(['suite'=>'resource-dispatch','checks'=>$checks,'result'=>'passed'],JSON_THROW_ON_ERROR)."\n";
}finally{$remove($root);}

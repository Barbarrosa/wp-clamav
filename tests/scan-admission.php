<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'WPClamAV\\')) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});
use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Resources\ResourceDeferred;
use WPClamAV\Scanning\IntakeService;
use WPClamAV\Scanning\LocalBackend;
use WPClamAV\Scanning\ProcessRunner;
use WPClamAV\Scanning\ProcessFailure;
use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\Files;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    ++$checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
};
$root = sys_get_temp_dir() . '/wp-clamav-scan-admission-' . bin2hex(random_bytes(12)); mkdir($root, 0700);
$clock = (object) ['now' => time()];
$settings = ['budgets' => ['native_as_bytes' => 134217728, 'native_process_bytes' => 268435456,
    'native_account_available_bytes' => 268435456, 'native_cpu_seconds' => 10, 'native_file_bytes' => 1048576,
    'worker_wall_seconds' => 10, 'request_work_seconds' => 10, 'cleanup_seconds' => 1,
    'php_work_bytes' => 16777216, 'cpu_hourly_seconds' => 10000, 'cpu_daily_seconds' => 100000,
    'cpu_monthly_seconds' => 4000000]];
$policy = new ResourcePolicy($settings, ['memory_limit' => ['value' => '128M']], static fn(): int => $clock->now);
$input = static function (string $bytes) { $s = fopen('php://temp', 'w+b'); fwrite($s, $bytes); rewind($s); return $s; };
try {
    $store = new FileSubmissionStore($root . '/private');
    $state = $root . '/state'; mkdir($state, 0700); mkdir($state . '/generations', 0700);
    $generation = 'gen-' . str_repeat('a', 64); mkdir($state . '/generations/' . $generation, 0700);
    Files::json($state . '/current.json', ['protocol' => 1, 'generation' => $generation]);
    mkdir($root . '/certs', 0700);
    $program = $root . '/scanner';
    $script = <<<'PHP'
<?php
$base=__DIR__;
file_put_contents($base.'/started',"started\n",FILE_APPEND);
$mode=trim(file_get_contents($base.'/mode'));
if($mode==='busy'){echo json_encode(['reason_codes'=>['scanner-or-update-busy']]);exit(2);}
if($mode==='bad'){echo '{}';exit(0);}
if($mode==='slow'){usleep(500000);exit(2);}
$arg=static function($key)use($argv){$p=array_search($key,$argv,true);return$p===false?null:$argv[$p+1];};
$path=$arg('--input');$profile=$arg('--profile');
echo json_encode(['protocol'=>1,'status'=>'clean','completed'=>true,'applicability_proven'=>true,
'coverage_qualified'=>true,'sha256'=>hash_file('sha256',$path),'size'=>filesize($path),
'requested_profile'=>$profile,'effective_profile'=>$profile,'engine_version'=>'process-fixture',
'generation_id'=>str_repeat('a',64),'findings'=>[],'reason_codes'=>[]]);
PHP;
    file_put_contents($program, '#!' . PHP_BINARY . "\n" . $script); chmod($program, 0700);
    file_put_contents($root . '/mode', 'good');
    $backend = new LocalBackend($program, $state, $store->scratchDirectory(), null, $root . '/certs');
    $notices = [];
    $intake = new IntakeService($store, $backend, $policy, 'full', static function (array $event) use (&$notices): void { $notices[] = $event; });
    $receive = static function (string $body, array $extra = []) use ($intake, $input): array {
        $s = $input($body);
        try { return $intake->receiveStream($s, $extra + ['name' => 'fixture.bin', 'size' => strlen($body)]); }
        finally { fclose($s); }
    };
    $advance = static function () use ($clock): void { $clock->now += 100; };
    $original = $receive('normal original');
    $slot = $policy->acquireDispatch();
    $deferred = $intake->assess($original['id']);
    $check($deferred['status'] === 'held' && !$deferred['completed'] && in_array('resource_deferred', $deferred['reason_codes'], true), 'busy admission is held and incomplete');
    $check(!file_exists($root . '/started') && glob($store->scratchDirectory() . '/scan-*') === [], 'busy admission starts no process and copies no original');
    $check($policy->diagnostics()['cpu_accounting']['windows']['hourly']['used_seconds'] == 0.0, 'busy admission consumes no CPU reservation');
    $check($notices === [], 'capacity deferral produces no notification storm');
    $check($intake->assess($original['id'])['id'] === $deferred['id'], 'retry before admission deadline does not grow scan history');
    $slot->release(); $advance();
    $clean = $intake->assess($original['id']);
    $check($clean['status'] === 'clean' && $intake->canPublish($original['id']), 'deferred original recovers through the same admission gate');
    $check(count(file($root . '/started')) === 1, 'exactly one admitted process ran');
    $slot = $policy->acquireDispatch(); $again = $intake->assess($original['id']);
    $check($again['status'] === 'held' && !$intake->canPublish($original['id']), 'new capacity hold revokes the previous clean capability');
    $check(count(file($root . '/started')) === 1, 'busy retry does not cold-start the engine'); $slot->release();

    foreach ([['', []], ['bad type', ['intake_holds' => ['unverified-file-type']]], ['unknown adapter', ['adapter_qualified' => false]]] as [$body, $context]) {
        $receipt = $receive($body, $context); $event = $intake->assess($receipt['id']);
        $check($event['status'] === 'held' && !$event['completed'] && !$intake->canPublish($receipt['id']), 'policy-held original never becomes scan approved');
    }
    $check(count(file($root . '/started')) === 1, 'empty, type-held and unqualified files initialize no engine');
    $bound = $receive('submitted evidence', ['origin' => 'forminator', 'site_id' => 1, 'form_id' => 2, 'scan_requires_submission' => true]);
    $intake->assess($bound['id']);
    $check(count(file($root . '/started')) === 1, 'unsubmitted AJAX evidence costs no native work');
    $fields = ['origin' => 'forminator', 'site_id' => 1, 'form_id' => 2, 'scan_receipt_ids' => []];
    $store->commitSubmission('count-held', $fields, [$bound['id']]);
    $intake->assess($bound['id'], null, false, 'count-held');
    $check(count(file($root . '/started')) === 1, 'mere receipt membership cannot bypass final submission policy');
    $fields['scan_receipt_ids'] = [$bound['id']]; $store->commitSubmission('eligible', $fields, [$bound['id']]); $advance();
    $check($intake->assess($bound['id'], null, false, 'eligible')['status'] === 'clean', 'trusted committed subset activates the first native assessment');

    $before = $policy->diagnostics()['cpu_accounting']['windows']['hourly']['used_seconds'];
    file_put_contents($root . '/mode', 'busy'); $advance();
    $busy = $intake->assess($bound['id'], null, true);
    $after = $policy->diagnostics()['cpu_accounting']['windows']['hourly']['used_seconds'];
    $check(in_array('native_state_busy', $busy['reason_codes'], true) && $after - $before < 2, 'native state contention retains measured CPU and avoids charging a full nine-second job');
    file_put_contents($root . '/mode', 'bad'); $advance();
    $bad = $intake->assess($bound['id'], null, true);
    $check($bad['status'] === 'failed' && !$bad['completed'], 'malformed scanner result remains a failed attempt');
    $check($policy->diagnostics()['cpu_accounting']['windows']['hourly']['used_seconds'] - $after < 2, 'failed native output retains its measured process CPU');

    $pending = $policy->signalMaintenance();
    try { $policy->acquireDispatch(); $check(false, 'maintenance wins new scan admission'); }
    catch (ResourceDeferred $e) { $check($e->reason === 'maintenance_pending', 'maintenance intent blocks new public work'); }
    $maintenance = $policy->acquireDispatch('maintenance'); $maintenance->release();
    $newer = $policy->signalMaintenance(); $policy->finishMaintenanceIntent($pending);
    try { $policy->acquireDispatch(); $check(false, 'stale intent finish cannot clear newer intent'); }
    catch (ResourceDeferred) { $check(true, 'stale intent cannot erase maintenance priority'); }
    $policy->finishMaintenanceIntent($newer); $slot = $policy->acquireDispatch(); $slot->release();
    $check(true, 'finishing maintenance restores scan admission');
    $policy->signalMaintenance(); $clock->now += 2000; $slot = $policy->acquireDispatch(); $slot->release();
    $check(true, 'crashed maintenance priority expires without dropping originals');

    $runner = new ProcessRunner();
    try { $runner->run([PHP_BINARY, '-r', 'usleep(500000);'], ['wall_seconds' => 5, 'output_bytes' => 4096, 'deadline_monotonic' => hrtime(true) / 1e9 + 0.05]); $check(false, 'absolute deadline'); }
    catch (ProcessFailure $error) { $check($error->cpuSeconds !== null && $error->cpuSeconds < 1, 'absolute timeout reaps the child and preserves known CPU'); }
    $limits = $policy->nativeLimits(false); $limits['deadline_monotonic'] = hrtime(true) / 1e9 - 1;
    $before = count(file($root . '/started')); $stream = $store->open($original['id']);
    try { $backend->scan($stream, $original, 'full', $limits); $check(false, 'expired preparation'); }
    catch (ProcessFailure $error) { $check($error->cpuSeconds === 0.0, 'expired preparation fails before spawning a child'); }
    finally { fclose($stream); }
    $check(count(file($root . '/started')) === $before && glob($store->scratchDirectory() . '/scan-*') === [], 'failed preparation leaves no native process or scratch copy');
    echo json_encode(['suite' => 'scan-admission', 'checks' => $checks, 'result' => 'passed']) . "\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}

<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'WPClamAV\\')) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});

use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\StorageException;
use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Scanning\IntakeService;
use WPClamAV\Scanning\ScannerBackend;
use WPClamAV\Scanning\ScanResult;
use WPClamAV\Scanning\ProcessRunner;

$checks = 0;
function check(bool $condition, string $message): void { global $checks; ++$checks; if (!$condition) { throw new RuntimeException('FAIL: ' . $message); } }
function rejects(callable $call, string $message): Throwable {
    try { $call(); } catch (Throwable $e) { check(true, $message); return $e; }
    check(false, $message); throw new RuntimeException('unreachable');
}
function streamFor(string $bytes) { $f = fopen('php://temp', 'w+b'); fwrite($f, $bytes); rewind($f); return $f; }
function removeFixture(string $path): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (new DirectoryIterator($path) as $entry) { if (!$entry->isDot()) { removeFixture($entry->getPathname()); } }
    rmdir($path);
}
$temp = sys_get_temp_dir() . '/wp-clamav-core-' . bin2hex(random_bytes(16)); mkdir($temp, 0700);
try {
    check(ResourcePolicy::bytes('768M') === 805306368, 'PHP binary memory units');
    check(ResourcePolicy::bytes('1KB') === 1024, 'explicit byte units');
    check(ResourcePolicy::bytes('0', true) === null, 'zero POST limit means unlimited');
    check(ResourcePolicy::bytes('0') === 0, 'zero file ceiling stays zero');
    check(ResourcePolicy::bytes('-1') === null, 'unlimited is not a fake finite cap');
    rejects(fn() => ResourcePolicy::bytes('999999999999999999999T'), 'overflow fails');
    rejects(fn() => ResourcePolicy::bytes('-2M'), 'negative non-sentinel fails');
    $settings = ['budgets' => ['storage_bytes' => 10000000, 'storage_reserve_bytes' => 1000, 'update_reserve_bytes' => 1000,
        'native_as_bytes' => 256000000, 'native_process_bytes' => 512000000, 'native_cpu_seconds' => 10,
        'worker_wall_seconds' => 10, 'request_work_seconds' => 10, 'cleanup_seconds' => 1, 'native_file_bytes' => 1000000,
        'mail_recipient_hourly' => 3, 'mail_provider_hourly' => 5, 'mail_reserve_hourly' => 1, 'other_mail_hourly' => 0,
        'mail_message_bytes' => 10000, 'mail_php_bytes' => 100000, 'mail_message_overhead_bytes' => 1000, 'mail_memory_multiplier' => 3]];
    $observed = ['upload_max_filesize' => ['value' => '8M'], 'post_max_size' => ['value' => '10M'], 'wp_upload_bytes' => ['value' => 4000000],
        'max_file_uploads' => ['value' => 10], 'memory_limit' => ['value' => '128M'], 'php_usage' => ['value' => 1000000], 'max_execution_time' => ['value' => 120]];
    $policy = new ResourcePolicy($settings, $observed);
    check($policy->fileLimit() === 4000000, 'WordPress filtered limit wins');
    check($policy->fileLimit(['field_limit' => 2000000]) === 2000000, 'form field ceiling wins');
    check($policy->requestLimit(100, 200) === 10 * 1024 * 1024 - 300, 'POST counts complete body overhead');
    check($policy->fileCount(5) === 5, 'count is independent of per-file bytes');
    check($policy->nativeLimits(false)['as_bytes'] === 256000000, 'native AS separate from PHP memory');
    check($policy->nativeLimits(false)['wall_seconds'] === 9.0, 'cleanup allowance retained');
    $rssSettings = $settings;
    $rssSettings['budgets'] = array_replace($rssSettings['budgets'], ['native_as_bytes' => 900000000,
        'native_process_bytes' => 768000000, 'measured_native_rss_bytes' => 600000000]);
    check((new ResourcePolicy($rssSettings, $observed))->nativeLimits(false)['as_bytes'] === 900000000, 'measured RSS receives headroom once while virtual space stays distinct');
    $rssSettings['budgets']['measured_native_rss_bytes'] = 650000000;
    rejects(fn() => (new ResourcePolicy($rssSettings, $observed))->nativeLimits(false), 'measured RSS plus headroom must fit actual resident allowance');
    check($policy->remoteLimits(false)['wall_seconds'] === 9.0, 'remote does not need native allocations');
    $unknown = new ResourcePolicy([], $observed);
    rejects(fn() => $unknown->nativeLimits(false), 'unknown native resources hold');
    rejects(fn() => $unknown->storageHeadroom($temp, []), 'unknown retention allocation blocks acknowledgement');
    $inodeSettings = $settings;
    $inodeSettings['budgets'] = array_replace($inodeSettings['budgets'], ['inodes_remaining' => 2, 'inode_reserve' => 1]);
    $inodePolicy = new ResourcePolicy($inodeSettings, $observed);
    check($inodePolicy->storageHeadroom($temp, [], 1)['available_bytes'] > 0, 'single metadata operation needs one inode');
    rejects(fn() => $inodePolicy->storageHeadroom($temp, [], 3), 'new original requires its full inode reservation');
    check(!$unknown->attachmentAllowed(1, 6), 'unknown mail envelope means no attachment');
    check(ResourcePolicy::encodedAttachmentBytes(3) === 6, 'MIME line terminator charged');
    check(ResourcePolicy::encodedAttachmentBytes(57) === 78, 'MIME 76-column wrap');
    check($policy->attachmentAllowed(10, 18), 'small qualified attachment fits');
    check(!$policy->attachmentAllowed(10000, 14000), 'encoded message cap applies');
    check(str_contains(json_encode($policy->diagnostics()), 'unknown'), 'unknown aggregate metrics disclosed');
    mkdir($temp . '/public', 0700);
    rejects(fn() => new FileSubmissionStore($temp . '/public/private', [$temp . '/public']), 'no storage under web root');
    mkdir($temp . '/unsafe', 0755); chmod($temp . '/unsafe', 0755);
    rejects(fn() => new FileSubmissionStore($temp . '/unsafe'), 'non-private store rejected');
    $store = new FileSubmissionStore($temp . '/private', [$temp . '/public'], fn($n, $u, $c) => $policy->admitStorage($temp, $n, $u, $c));
    $input = streamFor('original bytes');
    $receipt = $store->ingestStream($input, ['name' => '../report.txt', 'size' => 14]); fclose($input);
    check($receipt['name'] === 'report.txt' && $receipt['complete'], 'canonical name and complete persistence');
    check($receipt['sha256'] === hash('sha256', 'original bytes'), 'original hashed while streaming');
    check($receipt['status'] === 'held' && $receipt['latest_event_id'] === null, 'intake is not a clean verdict');
    check((fileperms($store->directory() . '/objects/' . $receipt['id']) & 0777) === 0400, 'original protected read-only');
    $stored = $store->open($receipt['id']); check(stream_get_contents($stored) === 'original bytes', 'exact original round trip'); fclose($stored);
    $again = new FileSubmissionStore($temp . '/private', [$temp . '/public']);
    check($again->get($receipt['id']) === $receipt, 'new process/provider preserves all metadata');
    rejects(fn() => $store->get('../anything'), 'path traversal denied');
    $partialInput = streamFor('short');
    $partialError = rejects(fn() => $store->ingestStream($partialInput, ['name' => 'partial', 'size' => 10]), 'truncated upload never acknowledged'); fclose($partialInput);
    check($partialError instanceof StorageException && $partialError->receiptId !== null, 'partial receipt reference retained');
    $partial = $store->get($partialError->receiptId);
    check(!$partial['complete'] && $partial['status'] === 'failed' && $partial['size'] === 5, 'partial original remains durable without fake success');
    $stream = $store->open($partial['id']); check(stream_get_contents($stream) === 'short', 'partial bytes not auto-purged'); fclose($stream);
    $store->commitSubmission('form-1-entry-1', ['narrative' => 'retained independently'], [$receipt['id']]);
    $store->commitSubmission('form-1-entry-1', ['narrative' => 'retained independently'], [$receipt['id']]);
    rejects(fn() => $store->commitSubmission('form-1-entry-1', ['narrative' => 'replaced']), 'immutable narrative requires explicit revision');
    check(file_exists($store->directory() . '/submissions/form-1-entry-1.json'), 'narrative persisted outside public root');
    check($policy->consumeMail($store, 'one@example.test', 1700000000), 'first recipient token');
    check($policy->consumeMail($store, 'two@example.test', 1700000000), 'each recipient charged');
    check(!$policy->consumeMail($store, 'three@example.test', 1700000000), 'reserve protects other site mail');
    check($policy->consumeMail($store, 'three@example.test', 1700003601), 'rolling hour replenishes');
    $backend = new class implements ScannerBackend {
        public string $mode = 'clean';
        public function scan($original, array $receipt, string $profile, array $limits): array {
            if ($this->mode === 'crash') { throw new RuntimeException('native failure'); }
            return ['protocol' => 1, 'status' => $this->mode === 'lie' ? 'clean' : $this->mode, 'completed' => true,
                'applicability_proven' => true, 'coverage_qualified' => true, 'sha256' => $receipt['sha256'], 'size' => $receipt['size'],
                'requested_profile' => $profile, 'effective_profile' => $profile, 'engine_version' => 'test', 'generation_id' => 'fixed-test-generation',
                'findings' => $this->mode === 'lie' ? ['a detection'] : [], 'reason_codes' => []];
        }
    };
    $events = [];
    $intake = new IntakeService($store, $backend, $policy, 'full', function ($event) use (&$events) { $events[] = $event; });
    $clean = $intake->assess($receipt['id']);
    check($clean['status'] === 'clean' && $intake->canPublish($receipt['id']), 'qualified matching result permits publication');
    $backend->mode = 'crash'; $failed = $intake->assess($receipt['id']);
    check($failed['status'] === 'failed' && !$failed['completed'] && !$intake->canPublish($receipt['id']), 'failed retry revokes earlier clean');
    check($store->event($clean['id'])['status'] === 'clean', 'retry does not rewrite immutable earlier event');
    check(count($events) === 2, 'only terminal results notified');
    $backend->mode = 'lie';
    check($intake->assess($receipt['id'])['status'] === 'failed', 'contradictory clean result denied');
    $backend->mode = 'clean';
    $held = $store->ingestStream(streamFor('held content'), ['name' => 'hold.txt', 'size' => 12, 'adapter_qualified' => false]);
    check($intake->assess($held['id'])['status'] === 'held' && !$intake->canPublish($held['id']), 'adapter hold survives clean scanner');
    rejects(fn() => $intake->authorizeManual($held['id'], 1, ''), 'approval needs reason');
    $intake->authorizeManual($held['id'], 1, 'Explicit review approval');
    check($intake->canPublish($held['id']), 'manual approval is separately auditable');
    $intake->assess($held['id']);
    check(!$intake->canPublish($held['id']), 'new assessment invalidates previous manual approval');
    $path = $store->directory() . '/objects/' . $receipt['id'];
    chmod($path, 0600); file_put_contents($path, 'different bytes'); chmod($path, 0400);
    check(!$intake->canPublish($receipt['id']), 'original tampering cannot reuse scan result');
    check($intake->assess($receipt['id'])['status'] === 'failed', 'integrity failure becomes failed event');
    rejects(fn() => $store->delete($held['id'], 0, 'missing actor'), 'deletion needs authorized actor');
    $store->delete($held['id'], 1, 'Administrator explicit removal');
    rejects(fn() => $store->open($held['id']), 'deleted original cannot be downloaded');
    check($store->latestEvent($held['id']) !== null, 'explicit object deletion retains decision audit');
    check(count($store->list()) >= 3, 'records including abandoned receipts remain retained');
    $runner = new ProcessRunner();
    $limits = ['wall_seconds' => 2, 'output_bytes' => 4096];
    $result = $runner->run([PHP_BINARY, '-r', 'fwrite(STDOUT,"done");fwrite(STDERR,"diagnostic");exit(3);'], $limits);
    check($result['exit_code'] === 3 && $result['stdout'] === 'done' && $result['stderr'] === 'diagnostic', 'supervisor handles both streams and disposition exit');
    rejects(fn() => $runner->run([PHP_BINARY, '-r', 'echo str_repeat("x",10000);'], $limits), 'output overflow fails');
    rejects(fn() => $runner->run([PHP_BINARY, '-r', 'usleep(500000);'], ['wall_seconds' => 0.05, 'output_bytes' => 4096]), 'independent wall timeout kills work');
    if (function_exists('pcntl_fork') && function_exists('posix_setsid') && function_exists('posix_kill')) {
        $escaped = $temp . '/escaped-descendant';
        $childCode = 'posix_setsid();$pid=pcntl_fork();if($pid===0){usleep(400000);file_put_contents(' . var_export($escaped, true) . ',"escaped");exit;}exit;';
        rejects(fn() => $runner->run([PHP_BINARY, '-r', $childCode], ['wall_seconds' => 0.15, 'output_bytes' => 4096]), 'exited parent cannot leave a descendant holding the protocol pipe');
        usleep(350000);
        check(!file_exists($escaped), 'supervision failure kills the group even after parent exit');
    }
    echo "Core: $checks checks passed\n";
} finally { removeFixture($temp); }

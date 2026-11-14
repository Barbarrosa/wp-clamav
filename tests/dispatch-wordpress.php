<?php
declare(strict_types=1);
$wordpress = getenv('WP_CLAMAV_WORDPRESS_DIR');
if (!$wordpress || realpath($wordpress) !== '/workspace/wp-integration-site' || !is_file($wordpress . '/wp-load.php')) {
    echo json_encode(['suite' => 'dispatch-wordpress', 'result' => 'skipped', 'reason' => 'Use the marked disposable single-site WordPress fixture.']) . "\n"; exit(0);
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:8081'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require $wordpress . '/wp-load.php';
if (!defined('WP_CLAMAV_INTEGRATION_TEST') || WP_CLAMAV_INTEGRATION_TEST !== true || is_multisite()
    || $wp_version !== '7.1.4' || untrailingslashit(get_option('siteurl')) !== 'http://127.0.0.1:8081') {
    throw new RuntimeException('Refusing to modify an unmarked or unexpected WordPress site.');
}
use WPClamAV\Application;
use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Scanning\DurableScanQueue;
use WPClamAV\Scanning\ScannerBackend;
use WPClamAV\Storage\Files;
use WPClamAV\WordPress\Plugin;
use WPClamAV\WordPress\Settings;
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { ++$checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $message); } };
$root = sys_get_temp_dir() . '/wp-clamav-dispatch-' . bin2hex(random_bytes(12)); mkdir($root, 0700);
$oldSettings = get_option(Settings::OPTION, null); $oldCron = get_option('cron'); $ids = [];
$clock = (object) ['now' => time()];
$backend = new class implements ScannerBackend {
    public array $calls = [];
    public function scan($stream, array $receipt, string $profile, array $limits): array {
        $this->calls[] = ['id' => $receipt['id'], 'profile' => $profile, 'limits' => $limits];
        return ['protocol' => 1, 'status' => 'clean', 'completed' => true, 'applicability_proven' => true,
            'coverage_qualified' => true, 'sha256' => $receipt['sha256'], 'size' => $receipt['size'],
            'requested_profile' => $profile, 'effective_profile' => $profile, 'engine_version' => 'dispatch-fixture-only',
            'generation_id' => 'dispatch-fixture-only', 'findings' => [], 'reason_codes' => [], 'local_cpu_seconds' => 0.001];
    }
};
$backendFilter = static fn($configured, array $settings): ScannerBackend => $backend;
$resourceFilter = static fn($configured, array $settings): ResourcePolicy => new ResourcePolicy($settings, null, static fn(): int => $clock->now);
add_filter('wp_clamav_backend', $backendFilter, PHP_INT_MAX, 2);
add_filter('wp_clamav_resource_policy', $resourceFilter, PHP_INT_MAX, 2);
$receive = static function (Application $app, string $bytes, array $context = []) use (&$ids): array {
    $input = fopen('php://temp', 'w+b'); fwrite($input, $bytes); rewind($input);
    try { $receipt = $app->intake->receiveStream($input, ['name' => 'dispatch.bin', 'size' => strlen($bytes), 'adapter_qualified' => true] + $context); }
    finally { fclose($input); }
    $ids[] = $receipt['id']; return $receipt;
};
$cronCounts = static function (): array {
    $counts = ['dispatch' => 0, 'legacy' => 0];
    foreach (_get_cron_array() as $hooks) {
        $counts['dispatch'] += count($hooks['wp_clamav_dispatch'] ?? []);
        $counts['legacy'] += count($hooks['wp_clamav_scan_receipt'] ?? []);
    }
    return $counts;
};
try {
    $settings = array_replace(Settings::defaults(), ['enabled' => true, 'backend' => 'remote', 'endpoint' => 'https://fixture.invalid',
        'storage_path' => $root, 'profile' => 'pdf-jpeg-png-pe-exclusions', 'notification_rules' => [],
        'budgets' => ['storage_bytes' => 268435456, 'storage_reserve_bytes' => 1048576, 'update_reserve_bytes' => 1048576,
            'account_remaining_bytes' => 536870912, 'inodes_remaining' => 10000, 'quota_observed_at' => time(),
            'file_bytes' => 1048576, 'php_work_bytes' => 33554432, 'native_as_bytes' => 134217728,
            'native_process_bytes' => 268435456, 'native_account_available_bytes' => 536870912,
            'native_cpu_seconds' => 5, 'native_file_bytes' => 1048576, 'worker_wall_seconds' => 10,
            'request_work_seconds' => 10, 'cleanup_seconds' => 1, 'worker_batch_files' => 20,
            'cpu_hourly_seconds' => 10000, 'cpu_daily_seconds' => 100000, 'cpu_monthly_seconds' => 1000000]]);
    update_option(Settings::OPTION, $settings); Plugin::reset(); $app = Plugin::app();
    wp_unschedule_hook('wp_clamav_dispatch'); wp_unschedule_hook('wp_clamav_scan_receipt');
    $receipts = [];
    for ($i = 0; $i < 8; ++$i) {
        $receipt = $receive($app, 'tiny-public-file-' . $i);
        $receipts[] = $receipt; Plugin::scheduleScan($receipt['id']);
    }
    $check(count($backend->calls) === 0, 'public receipt scheduling never starts a scanner');
    $check($cronCounts() === ['dispatch' => 1, 'legacy' => 0], 'a public upload flood creates one coalesced wake and zero per-original cron events: ' . json_encode($cronCounts()));
    $legacyAt = time() - 1; $legacyArgs = [$receipts[0]['id'], null];
    wp_schedule_single_event($legacyAt, 'wp_clamav_scan_receipt', $legacyArgs);
    $check($cronCounts()['legacy'] === 1, 'test creates one genuine pre-upgrade per-original WordPress cron event');
    // WordPress removes a ready single event before invoking its registered hook.
    wp_unschedule_event($legacyAt, 'wp_clamav_scan_receipt', $legacyArgs);
    do_action_ref_array('wp_clamav_scan_receipt', $legacyArgs);
    $check($cronCounts()['legacy'] === 0 && count($backend->calls) === 0, 'legacy cron drains into durable intent without native execution');
    for ($i = 0; $i < 20; ++$i) { Plugin::scanReceipt($receipts[0]['id']); }
    $queue = new DurableScanQueue($app->store);
    $check(count($backend->calls) === 0 && $queue->status($clock->now)['pending'] === 8, 'legacy callbacks only convert durable intent without duplicate requests or native work');
    $check($cronCounts() === ['dispatch' => 1, 'legacy' => 0], 'legacy conversion does not fan out cron state');
    $first = $app->runQueue();
    $check($first['scanned'] === 1 && count($backend->calls) === 1 && $first['held'] === 1, 'worker stops after first globally paced capacity deferral: ' . json_encode(['worker' => $first, 'calls' => count($backend->calls), 'first_event' => $app->store->latestEvent($receipts[0]['id'])]));
    $check($first['next_at'] > $clock->now && $queue->status($clock->now)['pending'] === 7, 'deferred original remains queued with a future wake');
    $before = Files::readJson($root . '/scan-queue.json');
    $deferredId = null;
    foreach ($before['ids'] as $id) { $event = $app->store->latestEvent($id); if (in_array('resource_deferred', $event['reason_codes'] ?? [], true)) { $deferredId = $id; break; } }
    $check(is_string($deferredId), 'capacity refusal is a typed held event');
    $marker = Files::readJson($root . '/scan-requests/' . $deferredId . '.json');
    for ($i = 0; $i < 20; ++$i) { Plugin::scanReceipt($deferredId); }
    $check(Files::readJson($root . '/scan-requests/' . $deferredId . '.json') === $marker, 'legacy/replayed references cannot reset attempts or retry deadline');
    $app->runQueue();
    $check(count($backend->calls) === 1, 'repeated wake-ups before retry_at perform no native work');
    for ($wave = 0; $wave < 12 && $queue->status($clock->now)['pending'] > 0; ++$wave) { $clock->now += 60; $app->runQueue(); }
    $check(count($backend->calls) === 8 && $queue->status($clock->now)['pending'] === 0, 'fair cursor eventually scans every admitted original once');
    $counts = array_count_values(array_column($backend->calls, 'id'));
    $check(count($counts) === 8 && max($counts) === 1, 'deferred recovery never duplicates completed scans');
    Plugin::scheduleScan($receipts[0]['id']); Plugin::scanReceipt($receipts[0]['id']); $app->runQueue();
    $check(count($backend->calls) === 8, 'terminal public receipt replay never resubmits native work');
    $racing = $receive($app, 'queued-then-synchronous'); Plugin::scheduleScan($racing['id']);
    $clock->now += 60;
    $synchronous = $app->intake->assess($racing['id']);
    $check($synchronous['status'] === 'clean' && count($backend->calls) === 9, 'a synchronous entry point may complete a previously queued original');
    $staleMarker = Files::readJson($root . '/scan-requests/' . $racing['id'] . '.json');
    $staleMarker['attempts'] = 3;
    Files::json($root . '/scan-requests/' . $racing['id'] . '.json', $staleMarker);
    $retired = $app->runQueue();
    $check($retired['scanned'] === 0 && $retired['skipped'] === 1 && count($backend->calls) === 9
        && $app->store->latestEvent($racing['id'])['id'] === $synchronous['id'], 'stale automatic intent retires before another scan or terminal-event replacement');
    $lockedCheck = $app->intake->assess($racing['id'], null, false, null, true);
    $check(($lockedCheck['_dispatch_skipped'] ?? false) === true && count($backend->calls) === 9
        && $lockedCheck['id'] === $synchronous['id'], 'the receipt-lock guard independently closes the queue precheck race');
    $afterRace = count($backend->calls);
    $orphan = $receive($app, 'orphan-form-file', ['origin' => 'forminator', 'site_id' => get_current_blog_id(), 'form_id' => 99, 'scan_requires_submission' => true]);
    $refused = Plugin::scheduleScan($orphan['id']);
    $check($refused['status'] === 'ineligible' && in_array('awaiting_submission', $refused['reasons'], true), 'orphan AJAX receipt has no automatic scan authority');
    $app->runQueue(); $check(count($backend->calls) === $afterRace, 'canonical reconciliation cannot scan an orphan or discard its original');
    $app->store->commitSubmission('dispatch-narrative', ['origin' => 'forminator', 'site_id' => get_current_blog_id(), 'form_id' => 99,
        'scan_receipt_ids' => [$orphan['id']], 'narrative' => 'Retained independently of scanner capacity.'], [$orphan['id']]);
    $check(Plugin::scheduleScan($orphan['id'], null, false, null, 'dispatch-narrative')['status'] === 'queued', 'committed trusted narrative can activate its first scan after awaiting_submission');
    $clock->now += 60; $app->runQueue();
    $check(count($backend->calls) === $afterRace + 1 && $app->store->submission('dispatch-narrative')['fields']['narrative'] !== '', 'authorized recovery preserves narrative and scans the original once');
    $policyHeld = $receive($app, 'type-policy-held', ['intake_holds' => ['unverified-file-type']]);
    $check(Plugin::scheduleScan($policyHeld['id'])['status'] === 'ineligible', 'known policy-held input never enters automatic queue');
    $check(Plugin::scheduleScan($policyHeld['id'], 'full', true, 1)['status'] === 'queued', 'authorized administrator can request a full scan explicitly');
    $clock->now += 60; $app->runQueue(); $event = $app->store->latestEvent($policyHeld['id']);
    $check(count($backend->calls) === $afterRace + 2 && end($backend->calls)['profile'] === 'full', 'durable administrator full profile reaches the backend');
    $check($event['status'] === 'held' && $event['completed'] === true && !$app->intake->canPublish($policyHeld['id']), 'explicit scan does not turn a policy violation into publication permission');
    $check(count(glob($root . '/audit/scan-request-*.json')) === 1, 'explicit administrator intent retains its actor audit');
    $pending = $receive($app, 'direct-entry-point');
    $sameWindow = $app->intake->assess($pending['id']);
    $check(in_array('resource_deferred', $sameWindow['reason_codes'], true) && count($backend->calls) === $afterRace + 2, 'direct entry point shares the worker CPU pacing pool');
    Plugin::scheduleScan($pending['id']);
    $check($queue->status($clock->now)['pending'] === 1, 'direct-entry capacity refusal is durably recoverable');
    $settings['budgets']['worker_batch_wall_seconds'] = 1.5;
    update_option(Settings::OPTION, $settings); Plugin::reset(); $clock->now += 60;
    $short = Plugin::app()->runQueue();
    $check(count($backend->calls) === $afterRace + 2 && $short['scanned'] === 0, 'an insufficient absolute batch deadline prevents engine startup');
    $check(is_file($root . '/objects/' . $pending['id']) && is_file($root . '/submissions/dispatch-narrative.json'), 'capacity/deadline limits retain original and canonical narrative');
    echo json_encode(['suite' => 'dispatch-wordpress', 'checks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
} finally {
    remove_filter('wp_clamav_backend', $backendFilter, PHP_INT_MAX); remove_filter('wp_clamav_resource_policy', $resourceFilter, PHP_INT_MAX);
    Plugin::reset(); $oldSettings === null ? delete_option(Settings::OPTION) : update_option(Settings::OPTION, $oldSettings); update_option('cron', $oldCron);
    global $wpdb; foreach ($ids as $id) { $wpdb->delete($wpdb->prefix . 'clamav_receipts', ['id' => $id]); }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname()); } @rmdir($root);
}

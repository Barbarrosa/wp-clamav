<?php
declare(strict_types=1);

/** Real multisite factory, private-admission and worker recovery integration. */
$wordpress = getenv('WP_CLAMAV_MULTISITE_WORDPRESS_DIR') ?: getenv('WP_CLAMAV_WORDPRESS_DIR');
if (!$wordpress || !is_file($wordpress . '/wp-load.php') || realpath($wordpress) !== '/workspace/wp-multisite-integration-site') {
    echo json_encode(['suite' => 'application-wordpress', 'result' => 'skipped',
        'reason' => 'Set WP_CLAMAV_MULTISITE_WORDPRESS_DIR to the separate disposable multisite fixture.']) . "\n";
    exit(0);
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:8082';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require $wordpress . '/wp-load.php';
if (!defined('WP_CLAMAV_INTEGRATION_TEST') || WP_CLAMAV_INTEGRATION_TEST !== true
    || !is_multisite() || DB_NAME !== 'wp_clamav_multisite_test'
    || $wp_version !== '7.1.4' || get_current_blog_id() !== 1
    || untrailingslashit(get_option('siteurl')) !== 'http://127.0.0.1:8082') {
    throw new RuntimeException('Refusing to change a site without the exact disposable multisite identity and test marker.');
}

use WPClamAV\Application;
use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Scanning\ScannerBackend;
use WPClamAV\Storage\Files;
use WPClamAV\WordPress\Plugin;
use WPClamAV\WordPress\Settings;

if (!class_exists(Plugin::class) || !Settings::networkManaged()) {
    throw new RuntimeException('Network-activate wp-clamav before running this suite.');
}
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
};
$rejects = static function (callable $operation, string $message, ?string $reason = null) use ($check): void {
    try { $operation(); }
    catch (Throwable $error) {
        $check($reason === null || str_contains($error->getMessage(), $reason), $message . ': ' . $error->getMessage());
        return;
    }
    $check(false, $message);
};
$receive = static function (Application $app, string $bytes, string $name): array {
    $input = fopen('php://temp', 'w+b');
    if ($input === false) { throw new RuntimeException('Cannot create fixture stream.'); }
    fwrite($input, $bytes); rewind($input);
    try { return $app->intake->receiveStream($input, ['size' => strlen($bytes), 'name' => $name, 'adapter_qualified' => true]); }
    finally { fclose($input); }
};
$settingsOf = static fn(Application $app): array => (new ReflectionProperty(Application::class, 'settings'))->getValue($app);
$root = sys_get_temp_dir() . '/wp-clamav-application-' . bin2hex(random_bytes(12));
mkdir($root, 0700);
$oldSettings = get_site_option(Settings::OPTION, null);
$switchDepth = count($GLOBALS['_wp_switched_stack'] ?? []);
$siteId = null;
$mainReceiptIds = [];
$mail = [];
$clock = (object) ['now' => time()];
$mailGuard = static function ($result, array $attributes) use (&$mail): bool { $mail[] = $attributes; return true; };
add_filter('pre_wp_mail', $mailGuard, PHP_INT_MAX, 2);
$backend = new class implements ScannerBackend {
    public int $calls = 0;
    public function scan($original, array $receipt, string $profile, array $limits): array
    {
        ++$this->calls;
        return ['protocol' => 1, 'status' => 'clean', 'completed' => true, 'applicability_proven' => true,
            'coverage_qualified' => true, 'sha256' => $receipt['sha256'], 'size' => $receipt['size'],
            'requested_profile' => $profile, 'effective_profile' => $profile, 'engine_version' => 'multisite-fixture-only',
            'generation_id' => 'fixture-generation', 'findings' => [], 'reason_codes' => []];
    }
};
$backendFilter = static fn($configured, array $settings): ScannerBackend => $backend;
add_filter('wp_clamav_backend', $backendFilter, 10, 2);
$resourceFilter = static fn($configured, array $settings): ResourcePolicy => new ResourcePolicy($settings, null, static fn(): int => $clock->now);
add_filter('wp_clamav_resource_policy', $resourceFilter, PHP_INT_MAX, 2);

try {
    // Never run this against the separate single-site fixture or reuse a real blog.
    $network = get_network();
    $siteId = wpmu_create_blog($network->domain, trailingslashit($network->path) . 'clamav-fixture-' . bin2hex(random_bytes(6)) . '/',
        'Disposable ClamAV application fixture', 1, ['public' => 0], (int) $network->id);
    if (is_wp_error($siteId)) { throw new RuntimeException($siteId->get_error_message()); }
    $siteId = (int) $siteId;
    $siteCount = (int) get_sites(['count' => true, 'number' => 0]);
    $check($siteCount === 2, 'fixture contains exactly the network main site and one temporary blog');
    $mail = [];
    $config = array_replace(Settings::defaults(), ['enabled' => true, 'backend' => 'remote',
        'endpoint' => 'https://example.test/scan', 'storage_path' => $root, 'profile' => 'full', 'notification_rules' => [],
        'budgets' => ['storage_bytes' => 3145728, 'storage_reserve_bytes' => 65536, 'update_reserve_bytes' => 65536,
            'account_remaining_bytes' => 67108864, 'inodes_remaining' => 10000, 'quota_observed_at' => time(),
            'file_bytes' => 2097152, 'php_work_bytes' => 33554432, 'native_as_bytes' => 134217728,
            'native_process_bytes' => 268435456, 'native_account_available_bytes' => 536870912,
            'native_cpu_seconds' => 5, 'native_file_bytes' => 2097152, 'worker_wall_seconds' => 6,
            'request_work_seconds' => 6, 'cleanup_seconds' => 1, 'worker_batch_files' => 20,
            'cpu_hourly_seconds' => 10, 'cpu_daily_seconds' => 20, 'cpu_monthly_seconds' => 40,
            'mail_recipient_hourly' => 3, 'mail_provider_hourly' => 3, 'mail_reserve_hourly' => 1, 'other_mail_hourly' => 0,
            'mail_message_bytes' => 1048576, 'mail_php_bytes' => 2097152,
            'mail_message_overhead_bytes' => 4096, 'mail_memory_multiplier' => 3]]);
    update_site_option(Settings::OPTION, $config);
    mkdir($root . '/native-state', 0700);
    Files::json($root . '/native-state/current.json', ['program' => 'invalid-local-program-identity']);
    Plugin::reset();
    $main = Plugin::app();
    $check(Plugin::app() === $main, 'same-blog factory calls reuse their application');
    $check($main->store->directory() === $root . '/site-1', 'main-site private originals use their own namespace');
    $check($settingsOf($main)['state_dir'] === $root . '/native-state', 'native database state defaults to the network private root');
    $check($settingsOf($main)['programs_dir'] === $root . '/programs', 'native programs default to the shared network private root');
    $check($main->runUpdates() === ['status' => 'remote-managed'], 'remote applications do not run native maintenance');
    $networkStorage = $main->doctor()['network_storage'];
    $check($networkStorage['configured_allocation_bytes'] === 3145728 && $networkStorage['site_count'] === 2,
        'diagnostics preserve the confirmed network allocation and current blog count');
    $check($networkStorage['per_site_allocation_bytes'] == 1572864 && $networkStorage['source'] !== '',
        'diagnostics explain the per-site share used for admission');

    $original = str_repeat('A', 921600);
    $first = $receive($main, $original, 'main-original.bin');
    $mainReceiptIds[] = $first['id'];
    $check($first['complete'] && $first['sha256'] === hash('sha256', $original), 'first site privately retains a complete original within its share');
    $rejects(fn() => $receive($main, str_repeat('B', 614400), 'main-over-share.bin'),
        'first site cannot spend the full network allocation', 'Insufficient reserved private capacity');
    $check($main->store->get($first['id'])['complete'] && is_file($main->store->directory() . '/objects/' . $first['id']),
        'quota rejection preserves the already accepted original');

    switch_to_blog($siteId);
    $other = Plugin::app();
    $check($other !== $main && Plugin::app() === $other, 'switch_to_blog creates and caches a different application');
    $check($other->store->directory() === $root . '/site-' . $siteId, 'switched blog uses its own canonical namespace');
    $check($settingsOf($other)['state_dir'] === $settingsOf($main)['state_dir']
        && $settingsOf($other)['programs_dir'] === $settingsOf($main)['programs_dir'], 'both blogs use one native state and program location');
    $rejects(fn() => @$other->store->get($first['id']), 'a switched blog cannot read another blog receipt');
    $second = $receive($other, str_repeat('C', 921600), 'other-original.bin');
    $check($second['complete'], 'second site can use its own private share after the first site fills much of its share');
    $rejects(fn() => $receive($other, str_repeat('D', 614400), 'other-over-share.bin'),
        'second site also cannot spend the full network allocation', 'Insufficient reserved private capacity');
    restore_current_blog();
    $restored = Plugin::app();
    $check($restored !== $other && $restored !== $main && $restored->store->directory() === $root . '/site-1',
        'restore_current_blog resets the factory and restores the original namespace');
    $check($restored->store->get($first['id'])['sha256'] === $first['sha256'], 'original receipt remains available after switching back');
    $rejects(fn() => @$restored->store->get($second['id']), 'main site cannot read the switched blog receipt');

    // A remote selection must ignore even a corrupt explicitly configured local pointer.
    mkdir($root . '/explicit-native-state', 0700);
    Files::json($root . '/explicit-native-state/current.json', ['program' => 'also-invalid']);
    $explicitRemote = Application::fromSettings(array_replace($config, ['state_dir' => $root . '/explicit-native-state']));
    $check($explicitRemote->runUpdates()['status'] === 'remote-managed', 'remote factory ignores invalid explicitly configured native state');
    unset($explicitRemote);

    $interrupted = $restored->store->appendEvent($first['id'], ['status' => 'held', 'completed' => false,
        'applicability_proven' => false, 'coverage_qualified' => false, 'requested_profile' => 'full',
        'effective_profile' => 'full', 'findings' => [], 'reason_codes' => ['scan_in_progress']]);
    $check(!$restored->intake->canPublish($first['id']), 'interrupted scan remains private before worker recovery');
    $recoveryIntent = $restored->queueScan($first['id']);
    $check($recoveryIntent['status'] === 'queued', 'interrupted receipt has durable recovery intent even when a prior quota refusal requires index repair');
    $lockPath = $restored->store->directory() . '/locks/scan-' . $first['id'];
    $lock = fopen($lockPath, 'c+b');
    if ($lock === false || !chmod($lockPath, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Cannot establish the live-scan lock fixture.');
    }
    try {
        $busy = $restored->runQueue();
        $check($busy['scanned'] === 0 && $busy['held'] === 1 && $backend->calls === 0,
            'worker does not duplicate a scan whose per-receipt lock is still owned: ' . json_encode($busy));
        $check($restored->store->latestEvent($first['id'])['id'] === $interrupted['id'], 'busy scan lock leaves the canonical interrupted event intact');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    $waiting = $restored->runQueue();
    $check($waiting['scanned'] === 0 && $backend->calls === 0, 'releasing a busy receipt does not bypass its durable retry deadline');
    $clock->now += 60;
    $recovered = $restored->runQueue();
    $terminal = $restored->store->latestEvent($first['id']);
    $check($recovered['scanned'] === 1 && $backend->calls === 1 && $terminal['status'] === 'clean',
        'released interrupted scan is recovered through the configured backend: ' . json_encode(['queue' => $recovered, 'calls' => $backend->calls, 'event' => $terminal]));
    $check($restored->intake->canPublish($first['id']) && $terminal['sha256'] === hash('sha256', $original),
        'only the complete qualified recovery event authorizes the exact retained original');
    $ack = $restored->store->directory() . '/notification-acks/' . $terminal['id'] . '.json';
    $check(is_file($ack) && Files::readJson($ack)['event_id'] === $terminal['id'], 'terminal notification enqueue acknowledgment is durable');
    $again = $restored->runQueue();
    $check($again['scanned'] === 0 && $backend->calls === 1, 'terminal receipt is not scanned again on the next worker pass');

    $unacknowledged = $restored->store->appendEvent($first['id'], array_replace($terminal, ['reason_codes' => ['replay-fixture']]));
    $replayAck = $restored->store->directory() . '/notification-acks/' . $unacknowledged['id'] . '.json';
    $check(!file_exists($replayAck), 'fixture represents a durable terminal event interrupted before notification enqueue');
    $restored->runQueue();
    $check(is_file($replayAck) && Files::readJson($replayAck)['event_id'] === $unacknowledged['id'] && $backend->calls === 1,
        'worker replays terminal notification work without rescanning the original');
    $check($mail === [], 'empty network notification rules do not send mail during queue recovery');

    $charged = $restored->policy->diagnostics()['cpu_accounting'];
    $check($charged['windows']['hourly']['used_seconds'] > 0 && $charged['windows']['hourly']['scan_used_seconds'] > 0,
        'the successful fixture scan passes real shared CPU accounting without a test bypass');
    // This phase tests a fresh shared maintenance reservation independently of
    // the preceding scan's legitimate charge and pacing. Advance the trusted
    // fixture clock past the longest rolling window; never rewrite CPU history.
    $clock->now += 2678400 + 120;
    $fresh = $other->policy->diagnostics()['cpu_accounting'];
    $check($fresh['windows']['monthly']['used_seconds'] === 0.0 && $fresh['pending_reservations'] === 0,
        'advancing beyond 31 days expires only operational CPU counters');
    $check($restored->store->get($first['id'])['sha256'] === hash('sha256', $original)
        && is_file($restored->store->directory() . '/objects/' . $first['id']), 'CPU window expiry retains the canonical original and receipt');
    // Use a five-second total account allocation for the isolated maintenance
    // reservation phase. Both blogs reload that same confirmed policy; the
    // earlier counters expired by time, rather than by changing configuration.
    $config['budgets']['cpu_hourly_seconds'] = 5;
    $config['budgets']['cpu_daily_seconds'] = 10;
    $config['budgets']['cpu_monthly_seconds'] = 20;
    update_site_option(Settings::OPTION, $config); Plugin::reset(); $restored = Plugin::app();
    switch_to_blog($siteId); $other = Plugin::app(); restore_current_blog();
    $limits = $restored->policy->nativeLimits(false);
    $reservation = $restored->policy->beginNative('multisite:shared-fixture', $limits);
    $check(is_string($reservation) && is_file($root . '/account-control/cpu-budget.json'), 'native reservation is durable in the shared account coordinator');
    $otherCpu = $other->policy->diagnostics()['cpu_accounting'];
    $check($otherCpu['pending_reservations'] === 1 && (float) $otherCpu['windows']['hourly']['remaining_seconds'] === 0.0,
        'second blog observes CPU reserved by the first blog: ' . json_encode($otherCpu));
    $rejects(fn() => $other->policy->nativeLimits(false), 'second blog cannot reuse the first blog CPU allowance', 'cpu_allocation_exhausted');
    $restored->policy->finishNative($reservation, 1.25);
    $settled = $other->policy->diagnostics()['cpu_accounting'];
    $check($settled['pending_reservations'] === 0 && $settled['windows']['hourly']['remaining_seconds'] === 3.75,
        'settling measured CPU restores only the unused shared reservation');
    $check(!is_file($root . '/site-1/cpu-budget.json') && !is_file($root . '/site-' . $siteId . '/cpu-budget.json'),
        'blog namespaces cannot maintain separate spendable copies of the shared CPU budget');
    $mailNow = time();
    $check($restored->policy->consumeMail($restored->store, 'first@example.test', $mailNow)
        && $restored->policy->consumeMail($restored->store, 'second@example.test', $mailNow),
        'first blog consumes the confirmed shared mail allowance after reserves');
    $check(!$other->policy->consumeMail($other->store, 'third@example.test', $mailNow),
        'second blog cannot reuse mail tokens already consumed by the first blog');
    $check(is_file($root . '/account-control/mail-budget.json')
        && !is_file($root . '/site-1/mail-budget.json') && !is_file($root . '/site-' . $siteId . '/mail-budget.json'),
        'mail accounting persists only in the shared network coordinator');

    echo json_encode(['suite' => 'application-wordpress', 'wordpress' => $wp_version, 'multisite' => true,
        'checks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
} finally {
    while (count($GLOBALS['_wp_switched_stack'] ?? []) > $switchDepth) { restore_current_blog(); }
    remove_filter('wp_clamav_backend', $backendFilter, 10);
    remove_filter('wp_clamav_resource_policy', $resourceFilter, PHP_INT_MAX);
    Plugin::reset();
    $oldSettings === null ? delete_site_option(Settings::OPTION) : update_site_option(Settings::OPTION, $oldSettings);
    // Only rows and the blog created by this run are disposable; leave main-site tables intact.
    global $wpdb;
    foreach ($mainReceiptIds as $id) { $wpdb->delete($wpdb->prefix . 'clamav_receipts', ['id' => $id]); }
    if (is_int($siteId) && $siteId > 1) {
        $temporaryPrefix = $wpdb->get_blog_prefix($siteId);
        foreach (['clamav_receipts', 'clamav_outbox', 'clamav_grants', 'clamav_notification_throttles'] as $table) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $temporaryPrefix . $table . '`');
        }
        if (!function_exists('wpmu_delete_blog')) { require_once ABSPATH . 'wp-admin/includes/ms.php'; }
        wpmu_delete_blog($siteId, true);
    }
    remove_filter('pre_wp_mail', $mailGuard, PHP_INT_MAX);
    if (is_dir($root)) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($root);
    }
}

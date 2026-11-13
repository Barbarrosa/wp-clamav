<?php
declare(strict_types=1);

/** Opt-in real engine test. These fixture allocations are never production defaults. */
require_once __DIR__ . '/notifications-fixtures.php';

use WPClamAV\Notifications\NotificationManager;
use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Scanning\IntakeService;
use WPClamAV\Scanning\LocalBackend;
use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\Files;

$names = ['BINARY', 'STATE', 'GENERATION', 'CERTS', 'INPUTS'];
$env = [];
foreach ($names as $name) {
    $value = getenv('WP_CLAMAV_NATIVE_' . $name);
    if (!$value) {
        echo json_encode(['suite' => 'local-native', 'result' => 'skipped',
            'reason' => 'Supply explicit WP_CLAMAV_NATIVE_BINARY/STATE/GENERATION/CERTS/INPUTS to run the authentic engine fixture.']) . "\n";
        exit(0);
    }
    $env[$name] = $value;
}
$maxAge = (int) (getenv('WP_CLAMAV_NATIVE_TEST_MAX_AGE_SECONDS') ?: 172800);
if ($maxAge < 1 || $maxAge > 2592000) { throw new RuntimeException('The explicit historical test allowance may not exceed30days.'); }
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
};
$current = Files::readJson($env['STATE'] . '/current.json');
$check(realpath($env['GENERATION']) === realpath($env['STATE'] . '/generations/' . $current['generation']), 'explicit generation equals the active immutable state tuple');
$generation = substr($current['generation'], 4);
$root = sys_get_temp_dir() . '/wp-clamav-local-native-' . bin2hex(random_bytes(10));
mkdir($root, 0700);
$settings = ['budgets' => [
    'storage_bytes' => 67108864, 'account_remaining_bytes' => 67108864,
    'storage_reserve_bytes' => 1048576, 'update_reserve_bytes' => 1048576,
    'native_as_bytes' => 640 * 1048576, 'native_process_bytes' => 1073741824,
    'native_account_available_bytes' => 1073741824, 'native_cpu_seconds' => 110,
    'cpu_hourly_seconds' => 1000, 'cpu_daily_seconds' => 2000, 'cpu_monthly_seconds' => 6000,
    'worker_wall_seconds' => 65, 'request_work_seconds' => 65, 'cleanup_seconds' => 5,
    'native_file_bytes' => 512 * 1048576, 'php_work_bytes' => 67108864,
]];
$policy = new ResourcePolicy($settings, [
    'upload_max_filesize' => ['value' => '16M'], 'post_max_size' => ['value' => '32M'],
    'memory_limit' => ['value' => '128M'], 'max_execution_time' => ['value' => 0],
]);
$store = new FileSubmissionStore($root . '/private', [],
    static fn($n, $usage, $context) => $policy->admitStorage($root, $n, $usage, $context));
$repository = new NotificationMemoryRepository();
$messages = [];
$rule = NotificationManager::defaultRules('reviewer@example.test')[0];
$rule['events'] = ['clean', 'detected', 'held', 'failed'];
$rule['include_download_link'] = true;
$rule['body'] = '{receipt_id} {status} {download_link}';
$notifications = new NotificationManager($store, $repository,
    ['notification_rules' => [$rule], 'notification_rule_epoch' => 1,
        'download_url' => 'https://example.test/wp-admin/admin-post.php?action=wp_clamav_download',
        'otp_pepper' => str_repeat('native-test-pepper', 4)],
    static function ($message) use (&$messages): bool { $messages[] = $message; return true; },
    static fn($kind, $recipient, $bytes, $encoded): bool => true, $store->scratchDirectory());
$notify = static fn(array $event) => $notifications->onEvent($event);
$backend = new LocalBackend($env['BINARY'], $env['STATE'], $store->scratchDirectory(), null, $env['CERTS'], $maxAge);
$intake = new IntakeService($store, $backend, $policy, 'pdf-jpeg-png-pe-exclusions', $notify);
$receipts = [];
$events = [];
try {
    $check($policy->nativeLimits(false)['as_bytes'] === 640 * 1048576, 'reduced fixture explicitly uses640MiB address space');
    foreach (['clean.pdf' => 'clean', 'eicar.pdf' => 'detected', 'pe.pdf' => 'held'] as $name => $status) {
        $path = $env['INPUTS'] . '/' . $name;
        if (!is_file($path)) { throw new RuntimeException('Missing explicit input fixture: ' . $name); }
        $receipt = $intake->receivePath($path, ['name' => $name, 'origin' => 'local-native-test']);
        $receipts[$status] = $receipt;
        $check($receipt['sha256'] === hash_file('sha256', $path) && $receipt['size'] === filesize($path), $name . ' preserves the exact original identity');
        $stream = $store->open($receipt['id']);
        try { $check(hash('sha256', stream_get_contents($stream)) === $receipt['sha256'], $name . ' private bytes match its receipt'); }
        finally { fclose($stream); }
        $event = $intake->assess($receipt['id']);
        $events[$status] = $event;
        $check($event['status'] === $status, $name . ' actual native disposition is ' . $status . ': ' . json_encode($event));
        $check($event['sha256'] === $receipt['sha256'] && $event['size'] === $receipt['size'], $name . ' actual native result binds the preserved original');
        $check($event['generation_id'] === $generation && $event['engine_version'] !== 'unavailable', $name . ' actual native result binds the authentic engine/generation');
        $check($event['completed'] === true && $event['coverage_qualified'] === true, $name . ' scan completes with qualified generation coverage');
        $check($intake->canPublish($receipt['id']) === ($status === 'clean'), $name . ' publication follows its complete native disposition');
        echo 'PASS native ' . $name . ' -> ' . $status . "\n";
    }
    $check($events['detected']['findings'] !== [], 'actual signed database detects the standard EICAR test signature');
    $check($events['held']['applicability_proven'] === false
        && in_array('excluded-pe-consumer-reached', $events['held']['reason_codes'], true), 'embedded PE cannot reuse a reduced-profile clean result');
    $fullTest = getenv('WP_CLAMAV_NATIVE_FULL_TEST') === '1';
    if ($fullTest) {
        if (!in_array(getenv('CI'), ['true', '1'], true)) { throw new RuntimeException('The optional2GiB full-profile fixture is limited to explicit CI runs.'); }
        $fullSettings = $settings;
        $fullSettings['budgets'] = array_replace($settings['budgets'], ['native_as_bytes' => 2147483648,
            'native_process_bytes' => 3221225472, 'native_account_available_bytes' => 3221225472]);
        $fullPolicy = new ResourcePolicy($fullSettings);
        $check($fullPolicy->nativeLimits(false)['as_bytes'] === 2147483648, 'full-profile fixture explicitly uses2GiB address space only in CI');
        $fullIntake = new IntakeService($store, $backend, $fullPolicy, 'full', $notify);
        $full = $fullIntake->assess($receipts['clean']['id']);
        $check($full['status'] === 'clean' && $full['completed'] === true, 'actual full-profile clean PDF completes under its explicit CI envelope: ' . json_encode($full));
        $check($full['generation_id'] === $generation && $full['sha256'] === $receipts['clean']['sha256'], 'full-profile result binds the same authentic generation and original');
        $check($full['requested_profile'] === 'full' && $full['effective_profile'] === 'full', 'native full scan does not silently substitute the reduced profile');
        $check($fullIntake->canPublish($receipts['clean']['id']), 'qualified actual full scan can authorize publication');
        echo "PASS native full-profile clean.pdf -> clean (explicit CI2GiB fixture)\n";
    }
    $notifications->runQueue();
    $cleanId = $receipts['clean']['id'];
    $grant = null;
    foreach ($repository->grants as $candidate) {
        if ($candidate['upload_id'] === $cleanId) { $grant = $candidate; break; }
    }
    $check(is_array($grant) && $grant['revoked_at'] === 0, 'actual native clean event creates a recipient-bound review invitation');

    foreach (['malformed' => 'echo "not-json";', 'empty' => '', 'non-object' => 'echo "null";'] as $case => $program) {
        $broken = $root . '/broken-' . $case;
        file_put_contents($broken, '#!' . PHP_BINARY . "\n<?php " . $program . "\n");
        chmod($broken, 0500);
        $faultyBackend = new LocalBackend($broken, $env['STATE'], $store->scratchDirectory(), null, $env['CERTS'], $maxAge);
        $faultyIntake = new IntakeService($store, $faultyBackend, $policy, 'pdf-jpeg-png-pe-exclusions', $notify);
        $failed = $faultyIntake->assess($cleanId);
        $check($failed['status'] === 'failed' && !$failed['completed'], $case . ' native process output produces no approved result');
        $check(!$faultyIntake->canPublish($cleanId), $case . ' attempt revokes the earlier actual-native clean capability');
        $check($store->event($events['clean']['id'])['status'] === 'clean', $case . ' attempt preserves immutable earlier scanner evidence');
        $check($store->get($cleanId)['sha256'] === $receipts['clean']['sha256'], $case . ' attempt preserves the original hash');
        $check($repository->grants[$grant['id']]['revoked_at'] > 0, $case . ' failed attempt revokes the earlier recipient download grant');
    }
    $check(glob($store->scratchDirectory() . '/scan-*') === [], 'native and failed-process scan snapshots are removed after each attempt');
    echo json_encode(['suite' => 'local-native', 'result' => 'passed', 'checks' => $checks,
        'profile' => 'pdf-jpeg-png-pe-exclusions', 'native_as_bytes' => 640 * 1048576,
        'full_profile_ci_test' => $fullTest,
        'generation_id' => $generation, 'historical_test_max_age_seconds' => $maxAge,
        'scope' => 'Authentic database and PHP/native integration; not a live SiteGround qualification.'], JSON_THROW_ON_ERROR) . "\n";
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_link($path) || !is_dir($path)) { unlink($path); return; }
        foreach (new DirectoryIterator($path) as $entry) { if (!$entry->isDot()) { $remove($entry->getPathname()); } }
        rmdir($path);
    };
    $remove($root);
}

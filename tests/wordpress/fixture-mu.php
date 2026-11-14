<?php
/** TEST ONLY. Installer copies this into the disposable WordPress site's mu-plugins. */
declare(strict_types=1);
if (!defined('WP_CLAMAV_INTEGRATION_TEST') || WP_CLAMAV_INTEGRATION_TEST !== true
    || !str_contains((string) get_option('siteurl'), '127.0.0.1:8081')) {
    return;
}

$fixtureClockPath = '/workspace/wp-integration-clock.json';
$fixtureClock = is_file($fixtureClockPath) ? json_decode(file_get_contents($fixtureClockPath), true) : [];
$fixtureRealClock = hash_equals('integration-only-route-token', (string) ($_SERVER['HTTP_X_WP_CLAMAV_TEST'] ?? ''))
    && ($_SERVER['HTTP_X_WP_CLAMAV_TEST_CLOCK'] ?? '') === 'real';
$fixtureNow = $fixtureRealClock ? time() : max(time(), (int) ($fixtureClock['now'] ?? 0));
// TEST ONLY: enable the vendor's abandonment feature and its documented spam
// classifier. The public request still goes through real Forminator processing.
add_filter('forminator_form_abandonment_disabled', '__return_false');
if (hash_equals('integration-only-route-token', (string) ($_SERVER['HTTP_X_WP_CLAMAV_TEST'] ?? ''))
    && ($_SERVER['HTTP_X_WP_CLAMAV_TEST_LIFECYCLE'] ?? '') === 'spam') {
    add_filter('forminator_spam_protection', '__return_true');
}
if (hash_equals('integration-only-route-token', (string) ($_SERVER['HTTP_X_WP_CLAMAV_TEST'] ?? ''))
    && ($_SERVER['HTTP_X_WP_CLAMAV_TEST_VERSION'] ?? '') === 'unqualified' && !defined('FORMINATOR_VERSION')) {
    define('FORMINATOR_VERSION', '1.58.5');
}
add_filter('wp_clamav_resource_policy', static fn($policy, $settings) => new \WPClamAV\Resources\ResourcePolicy(
    $settings, null, static fn(): int => $fixtureNow), 10, 2);

add_filter('wp_clamav_backend', static function ($backend, $settings) use ($fixtureNow, $fixtureRealClock, $fixtureClockPath) {
    return new class($fixtureNow, $fixtureRealClock, $fixtureClockPath) implements \WPClamAV\Scanning\ScannerBackend {
        public function __construct(private int $now, private bool $realClock, private string $clockPath) {}
        public function scan($original, array $receipt, string $profile, array $limits): array
        {
            file_put_contents('/workspace/wp-integration-dispatch.jsonl', json_encode([
                'id' => $receipt['id'], 'sha256' => $receipt['sha256'], 'profile' => $profile, 'name' => $receipt['name'],
                'real_clock' => $this->realClock, 'at' => $this->now,
            ]) . "\n", FILE_APPEND | LOCK_EX);
            if (!$this->realClock) {
                $saved = is_file($this->clockPath) ? json_decode(file_get_contents($this->clockPath), true) : [];
                $pace = is_file('/workspace/wp-integration-private/cpu-budget.json')
                    ? json_decode(file_get_contents('/workspace/wp-integration-private/cpu-budget.json'), true) : [];
                file_put_contents($this->clockPath, json_encode(['now' => max($this->now, (int) ($saved['now'] ?? 0),
                    (int) ceil((float) ($pace['scan_next_at'] ?? 0))) + 1]), LOCK_EX);
            }
            rewind($original);
            $bytes = stream_get_contents($original);
            if (str_contains($bytes, 'TEST-FAILED')) {
                throw new RuntimeException('Integration fixture scan failure.');
            }
            $held = str_contains($bytes, 'TEST-HELD');
            $heldComplete = str_contains($bytes, 'TEST-HELD-REVIEW');
            $detected = str_contains($bytes, 'TEST-DETECTED');
            return [
                'protocol' => 1, 'status' => $held ? 'held' : ($detected ? 'detected' : 'clean'),
                'sha256' => $receipt['sha256'], 'size' => $receipt['size'],
                'completed' => !$held || $heldComplete, 'applicability_proven' => !$held, 'coverage_qualified' => !$held || $heldComplete,
                'requested_profile' => $profile, 'effective_profile' => $profile,
                'engine_version' => 'integration-fixture-not-a-malware-engine',
                'generation_id' => 'integration-fixture-database',
                'findings' => $detected ? ['Integration.Test.Detection'] : [],
                'reason_codes' => $held ? ['integration_incomplete'] : [],
                'local_cpu_seconds' => 0.001,
            ];
        }
    };
}, 10, 2);

add_filter('pre_wp_mail', static function ($return, array $mail) {
    $path = '/workspace/wp-integration-mail.jsonl';
    file_put_contents($path, json_encode($mail, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    return true;
}, 100, 2);

add_action('rest_api_init', static function (): void {
    $permission = static fn($request): bool => hash_equals('integration-only-route-token', (string) $request->get_header('x-wp-clamav-test'));
    register_rest_route('wp-clamav-tests/v1', '/nonce', ['methods' => 'GET', 'permission_callback' => $permission, 'callback' => static function ($request): array {
        if ($request->get_param('kind') === 'admin_download') {
            wp_set_current_user(1);
            return ['nonce' => wp_create_nonce('wp_clamav_download_original_' . $request->get_param('receipt') . '_' . $request->get_param('event_id') . '_' . $request->get_param('sha256'))];
        }
        if ($request->get_param('kind') === 'abandonment') {
            return ['nonce' => wp_create_nonce('forminator_abandonment_form' . (int) $request->get_param('form_id'))];
        }
        return ['nonce' => wp_create_nonce('forminator_submit_form' . (int) $request->get_param('form_id'))];
    }]);
    register_rest_route('wp-clamav-tests/v1', '/bits', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => static function ($request) {
        return wp_upload_bits((string) $request->get_param('name'), null, base64_decode((string) $request->get_param('bytes'), true));
    }]);
    register_rest_route('wp-clamav-tests/v1', '/sideload', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => static function ($request) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $bytes = base64_decode((string) $request->get_param('bytes'), true);
        $path = wp_tempnam('integration');
        file_put_contents($path, $bytes);
        $file = ['name' => (string) $request->get_param('name'), 'tmp_name' => $path, 'size' => strlen($bytes), 'error' => 0];
        return wp_handle_sideload($file, ['test_form' => false]);
    }]);
    register_rest_route('wp-clamav-tests/v1', '/custom-action', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => static function ($request) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = $request->get_file_params()['file'];
        return wp_handle_upload($file, ['test_form' => false, 'action' => 'integration_custom_upload']);
    }]);
    register_rest_route('wp-clamav-tests/v1', '/assess', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => static function ($request) {
        $submission = $request->get_param('submission_id');
        return \WPClamAV\WordPress\Plugin::intake()->assess((string) $request->get_param('id'), null, false, is_string($submission) ? $submission : null);
    }]);
    register_rest_route('wp-clamav-tests/v1', '/queue', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => static function ($request) {
        return \WPClamAV\WordPress\Plugin::app()->runQueue();
    }]);
    register_rest_route('wp-clamav-tests/v1', '/virtual-clock', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => static function () {
        $pace = is_file('/workspace/wp-integration-private/cpu-budget.json')
            ? json_decode(file_get_contents('/workspace/wp-integration-private/cpu-budget.json'), true) : [];
        $saved = is_file('/workspace/wp-integration-clock.json') ? json_decode(file_get_contents('/workspace/wp-integration-clock.json'), true) : [];
        $now = max(time(), (int) ($saved['now'] ?? 0), (int) ceil((float) ($pace['scan_next_at'] ?? 0))) + 1;
        file_put_contents('/workspace/wp-integration-clock.json', json_encode(['now' => $now]), LOCK_EX);
        return ['virtual_next_request' => $now];
    }]);
    register_rest_route('wp-clamav-tests/v1', '/tamper', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => static function ($request) {
        $store = \WPClamAV\WordPress\Plugin::storage();
        $receipt = $store->get((string) $request->get_param('id'));
        if (!str_contains($receipt['name'], '-admin-tamper')) { return new WP_Error('fixture_scope', 'Only the dedicated tamper fixture is allowed.', ['status' => 403]); }
        $path = $store->directory() . '/objects/' . $receipt['id'];
        chmod($path, 0600);
        file_put_contents($path, str_repeat('X', $receipt['size']));
        chmod($path, 0400);
        return ['tampered' => true];
    }]);
    register_rest_route('wp-clamav-tests/v1', '/state', ['methods' => 'GET', 'permission_callback' => $permission, 'callback' => static function (): array {
        $root = '/workspace/wp-integration-private';
        $submissions = [];
        foreach (glob($root . '/submissions/*.json') ?: [] as $path) {
            $submissions[] = json_decode(file_get_contents($path), true);
        }
        $uploads = wp_upload_dir();
        $publicFiles = [];
        if (is_dir($uploads['basedir'])) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads['basedir'], FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $publicFiles[] = ['name' => substr($item->getPathname(), strlen($uploads['basedir']) + 1), 'sha256' => hash_file('sha256', $item->getPathname())];
                }
            }
        }
        $mail = [];
        $mailLines = is_file('/workspace/wp-integration-mail.jsonl') ? file('/workspace/wp-integration-mail.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        foreach ($mailLines ?: [] as $line) {
            $mail[] = json_decode($line, true);
        }
        $receipts = [];
        for ($offset = 0; $offset < 1000; $offset += 100) {
            $page = \WPClamAV\WordPress\Plugin::storage()->list(100, $offset);
            $receipts = array_merge($receipts, $page);
            if (count($page) < 100) { break; }
        }
        $audit = [];
        foreach (glob($root . '/audit/*.json') ?: [] as $path) { $audit[] = json_decode(file_get_contents($path), true); }
        $dispatches = [];
        foreach (is_file('/workspace/wp-integration-dispatch.jsonl') ? file('/workspace/wp-integration-dispatch.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            $dispatches[] = json_decode($line, true);
        }
        $cron = [];
        foreach (_get_cron_array() ?: [] as $at => $hooks) {
            foreach ($hooks as $hook => $events) {
                if (in_array($hook, ['wp_clamav_dispatch', 'wp_clamav_scan_receipt'], true)) { $cron[] = ['at' => $at, 'hook' => $hook, 'count' => count($events)]; }
            }
        }
        return ['receipts' => $receipts, 'submissions' => $submissions, 'public_files' => $publicFiles, 'mail' => $mail, 'audit' => $audit, 'dispatches' => $dispatches, 'scan_cron' => $cron];
    }]);
});

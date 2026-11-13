<?php
declare(strict_types=1);

/** Real WordPress nonce/cookie/HTTP tests against an already running disposable site. */
require_once __DIR__ . '/notifications-fixtures.php';

use WPClamAV\Notifications\NotificationManager;
use WPClamAV\Notifications\WpdbNotificationRepository;
use WPClamAV\Notifications\WordPressNotificationController;
use WPClamAV\Storage\FileSubmissionStore;

function notification_http_components(array $configuration): array
{
    $store = new FileSubmissionStore($configuration['storage'], [ABSPATH]);
    $counter = $configuration['counter'];
    $countedStore = new class($store, $counter) {
        public function __construct(private object $inner, private string $counter) {}
        public function __call(string $method, array $arguments): mixed
        {
            if ($method === 'open') { file_put_contents($this->counter, "read\n", FILE_APPEND); }
            return $this->inner->$method(...$arguments);
        }
    };
    $db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $db->set_prefix($configuration['prefix']);
    $repository = new WpdbNotificationRepository($db);
    $rule = NotificationManager::defaultRules('external-reviewer@example.test')[0];
    $rule['events'] = ['clean', 'detected', 'held', 'failed'];
    $rule['include_download_link'] = true;
    $settings = ['notification_rules' => [$rule], 'notification_rule_epoch' => 1,
        'download_url' => preg_replace('~^http:~', 'https:', $configuration['url']) . '?action=wp_clamav_download',
        'otp_pepper' => str_repeat('http-fixture-pepper-', 3)];
    $mailer = static function (array $message) use ($configuration): bool {
        file_put_contents($configuration['mail'], json_encode($message, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        return true;
    };
    $manager = new NotificationManager($countedStore, $repository, $settings, $mailer,
        static fn(string $kind, string $recipient, int $original, int $encoded): bool => true, $store->scratchDirectory());
    return [$store, $repository, $manager, $db];
}

// The generated public router calls only this branch, with a fixed test config path.
if (defined('WP_CLAMAV_NOTIFICATION_HTTP_ROUTER')) {
    $configuration = require WP_CLAMAV_NOTIFICATION_HTTP_ROUTER;
    require $configuration['wordpress'] . '/wp-load.php';
    [$store, $repository, $manager] = notification_http_components($configuration);
    add_filter('admin_url', static function (string $url, string $path) use ($configuration): string {
        return $path === 'admin-post.php' ? $configuration['url'] : $url;
    }, 10, 2);
    add_filter('pre_schedule_event', static fn($pre, $event) => $event->hook === 'wp_clamav_mail_queue' ? false : $pre, 10, 2);
    (new WordPressNotificationController($manager))->downloadAction();
}

$wordpress = getenv('WP_CLAMAV_WORDPRESS_DIR');
$base = getenv('WP_CLAMAV_HTTP_BASE');
if (!$wordpress || !$base || !is_file($wordpress . '/wp-load.php')) {
    echo json_encode(['suite' => 'notifications-wordpress-http', 'result' => 'skipped',
        'reason' => 'Set WP_CLAMAV_WORDPRESS_DIR and WP_CLAMAV_HTTP_BASE for a disposable running WordPress site.']) . "\n";
    exit(0);
}
require $wordpress . '/wp-load.php';
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    ++$checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
};
$directory = sys_get_temp_dir() . '/wp-clamav-notification-http-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$routerName = 'notification-fixture-' . bin2hex(random_bytes(8)) . '.php';
$configuration = ['wordpress' => $wordpress, 'storage' => $directory . '/store',
    'prefix' => 'notification_http_' . bin2hex(random_bytes(4)) . '_', 'counter' => $directory . '/reads',
    'mail' => $directory . '/mail.jsonl', 'url' => rtrim($base, '/') . '/' . $routerName];
$configurationPath = $directory . '/config.php';
file_put_contents($configurationPath, '<?php return ' . var_export($configuration, true) . ';');
chmod($configurationPath, 0600);
$routerPath = $wordpress . '/' . $routerName;
file_put_contents($routerPath, '<?php define("WP_CLAMAV_NOTIFICATION_HTTP_ROUTER", ' . var_export($configurationPath, true)
    . '); require ' . var_export(__FILE__, true) . ';');
[$store, $repository, $manager, $db] = notification_http_components($configuration);
$mail = static function () use ($configuration): array {
    return array_map(static fn(string $line): array => json_decode($line, true, 64, JSON_THROW_ON_ERROR),
        is_file($configuration['mail']) ? file($configuration['mail'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []);
};
$reads = static fn(): int => is_file($configuration['counter']) ? count(file($configuration['counter'])) : 0;
$request = static function (string $method, array $parameters, string $cookie = '') use ($configuration): array {
    $headers = ['Connection: close'];
    if ($cookie !== '') { $headers[] = 'Cookie: ' . $cookie; }
    $url = $configuration['url'];
    $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 20, 'header' => $headers];
    if ($method === 'GET') { $url .= '?' . http_build_query($parameters); }
    else {
        $options['header'][] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($parameters);
    }
    $body = file_get_contents($url, false, stream_context_create(['http' => $options]));
    if ($body === false) { throw new RuntimeException('Unable to reach disposable WordPress test server.'); }
    return ['body' => $body, 'headers' => $http_response_header ?? [],
        'status' => (int) substr($http_response_header[0] ?? '', 9, 3)];
};
try {
    $repository->install();
    $input = fopen('php://temp', 'w+b'); fwrite($input, 'private-http-original'); rewind($input);
    $receipt = $store->ingestStream($input, ['name' => 'review.txt', 'size' => 21]); fclose($input);
    $event = $store->appendEvent($receipt['id'], ['status' => 'clean', 'completed' => true, 'applicability_proven' => true,
        'coverage_qualified' => true, 'findings' => [], 'reason_codes' => [], 'engine_version' => 'http-fixture',
        'generation_id' => 'http-fixture-generation', 'effective_profile' => 'full']);
    $manager->onEvent($event); $manager->runQueue();
    preg_match('~https://[^\s]+~', $mail()[0]['body'], $match);
    parse_str((string) parse_url($match[0], PHP_URL_QUERY), $parameters);
    $beforeReads = $reads(); $beforeMail = count($mail());
    $landing = $request('GET', $parameters);
    $check($landing['status'] === 200 && str_contains($landing['body'], 'Email verification code'), 'landing page renders verification form');
    $check(!str_contains($landing['body'], 'private-http-original') && $reads() === $beforeReads, 'GET cannot serve or read original bytes');
    $check(count($mail()) === $beforeMail, 'GET cannot send a verification email');
    $check((bool) preg_grep('/^Cache-Control: .*no-store/i', $landing['headers']), 'landing response cannot be cached');
    $check((bool) preg_grep('/^Referrer-Policy: no-referrer/i', $landing['headers']), 'invitation cannot leak through referrer');
    preg_match('/name="_wpnonce" value="([^"]+)"/', $landing['body'], $nonceMatch);
    $check(isset($nonceMatch[1]), 'real WordPress nonce rendered');
    $post = $parameters + ['stage' => 'request_otp'];
    $rejected = $request('POST', $post);
    $check($rejected['status'] === 403, 'missing CSRF nonce rejected');
    $post['_wpnonce'] = html_entity_decode($nonceMatch[1], ENT_QUOTES);
    $queued = $request('POST', $post);
    $check($queued['status'] === 200 && str_contains($queued['body'], 'verification email has been queued'), 'POST queues verification');
    $check(count($mail()) === $beforeMail, 'OTP request does not synchronously dispatch mail');
    $manager->runQueue(); $messages = $mail();
    preg_match('/Verification code: ([0-9]{6})/', $messages[array_key_last($messages)]['body'], $codeMatch);
    $check(isset($codeMatch[1]), 'worker sends OTP to configured recipient');
    $post['stage'] = 'verify_otp'; $post['code'] = 'invalid';
    $check($request('POST', $post)['status'] === 403, 'invalid OTP fails HTTP authorization');
    $post['code'] = $codeMatch[1]; $verified = $request('POST', $post);
    $check($verified['status'] === 200 && str_contains($verified['body'], 'Download original for review'), 'valid OTP permits review action');
    $cookies = preg_grep('/^Set-Cookie:/i', $verified['headers']);
    $cookieHeader = (string) reset($cookies);
    $check(str_contains(strtolower($cookieHeader), 'secure') && str_contains(strtolower($cookieHeader), 'httponly')
        && str_contains(strtolower($cookieHeader), 'samesite=strict'), 'session cookie is Secure HttpOnly SameSite Strict');
    preg_match('/^Set-Cookie: ([^;]+)/i', $cookieHeader, $cookieMatch);
    $check(isset($cookieMatch[1]), 'download session cookie set');
    $beforeReads = $reads();
    $verifiedGet = $request('GET', $parameters, $cookieMatch[1]);
    $check($verifiedGet['status'] === 200 && $reads() === $beforeReads && !str_contains($verifiedGet['body'], 'private-http-original'), 'verified GET still cannot serve original');
    $post['stage'] = 'download'; unset($post['code']);
    $check($request('POST', $post)['status'] === 403, 'download requires verified browser cookie');
    $download = $request('POST', $post, $cookieMatch[1]);
    $check($download['status'] === 200 && $download['body'] === 'private-http-original', 'authorized POST streams exact original');
    $check((bool) preg_grep('/^Content-Type: application\/octet-stream/i', $download['headers']), 'download is attachment bytes, not executable inline content');
    $failed = $store->appendEvent($receipt['id'], ['status' => 'failed', 'completed' => false, 'applicability_proven' => false,
        'coverage_qualified' => false, 'findings' => [], 'reason_codes' => ['fixture_failure']]);
    $manager->onEvent($failed); $manager->runQueue();
    $check($request('POST', $post, $cookieMatch[1])['status'] === 403, 'later failed rescan revokes an already verified browser');
    $last = $mail()[array_key_last($mail())];
    $check($last['event_id'] === $failed['id'] && $last['attachments'] === [] && !str_contains($last['body'], 'https://'), 'failed HTTP event notification has no original or link');
    $check(is_resource($store->open($receipt['id'])), 'revocation preserves canonical original');
    echo json_encode(['suite' => 'notifications-wordpress-http', 'checks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
} finally {
    @unlink($routerPath);
    foreach (['clamav_outbox', 'clamav_grants', 'clamav_notification_throttles'] as $table) {
        $db->query('DROP TABLE IF EXISTS `' . $configuration['prefix'] . $table . '`');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname()); }
    @rmdir($directory);
}

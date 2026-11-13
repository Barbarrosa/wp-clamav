<?php
declare(strict_types=1);

/** Optional real WordPress/wpdb test; CI's WordPress job supplies this directory. */
$wordpress = getenv('WP_CLAMAV_WORDPRESS_DIR');
if (!$wordpress || !is_file($wordpress . '/wp-load.php')) {
    echo json_encode(['suite' => 'notifications-database', 'result' => 'skipped',
        'reason' => 'Set WP_CLAMAV_WORDPRESS_DIR to an installed disposable WordPress test site.']) . "\n";
    exit(0);
}
require $wordpress . '/wp-load.php';
require_once __DIR__ . '/notifications-fixtures.php';

use WPClamAV\Notifications\WpdbNotificationRepository;

$db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$prefix = 'clamav_notification_test_' . bin2hex(random_bytes(4)) . '_';
$db->set_prefix($prefix);
$repository = new WpdbNotificationRepository($db);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    ++$checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
};
$now = 1700000000;
try {
    $repository->install();
    $row = ['id' => bin2hex(random_bytes(16)), 'kind' => 'scan', 'created_at' => $now,
        'next_at' => $now, 'delivery_key' => hash('sha256', 'automatic-one'),
        'payload' => ['event_id' => 'event', 'recipient' => 'recipient@example.test', 'literal' => "'quoted' 🤍"]];
    $id = $repository->enqueue($row);
    $duplicate = $row; $duplicate['id'] = bin2hex(random_bytes(16));
    $check($repository->enqueue($duplicate) === $id, 'database unique delivery key deduplicates');
    $check($repository->claim($now - 1, 30, 10) === [], 'future delivery not claimable');
    $lease = $repository->claim($now, 30, 10);
    $check(count($lease) === 1 && $lease[0]['payload'] === $row['payload'], 'payload and UTF-8 round trip');
    $other = new WpdbNotificationRepository($db);
    $check($other->claim($now, 30, 10) === [], 'second worker cannot claim current lease');
    $replacement = $other->claim($now + 31, 30, 10)[0];
    $check($replacement['lease_token'] !== $lease[0]['lease_token'], 'lease replacement changes ownership token');
    $repository->finish($id, $lease[0]['lease_token'], 'accepted', $now + 32, 0, '', true);
    $check($repository->delivery($id)['state'] === 'sending', 'expired worker cannot finish replacement lease');
    $other->finish($id, $replacement['lease_token'], 'retry', $now + 32, $now + 100, 'fixture retry', true);
    $delivery = $repository->delivery($id);
    $check($delivery['state'] === 'retry' && $delivery['attempts'] === 1 && $delivery['last_error'] === 'fixture retry', 'attempt state durably committed');
    $check($repository->claim($now + 99, 30, 10) === [], 'retry delay enforced');
    $lease2 = $repository->claim($now + 100, 30, 10)[0];
    $repository->finish($id, $lease2['lease_token'], 'accepted', $now + 101, 0, '', true);
    $check($repository->delivery($id)['state'] === 'accepted' && $repository->delivery($id)['attempts'] === 2, 'accepted is recorded separately from delivery');

    $grant = ['id' => bin2hex(random_bytes(16)), 'upload_id' => 'upload', 'event_id' => 'event',
        'expires_at' => $now + 86400, 'revoked_at' => 0, 'revision' => 0, 'token_hash' => hash('sha256', 'not-stored-raw')];
    $check($repository->createGrant($grant), 'grant inserted');
    $check(!$repository->createGrant($grant), 'grant primary key deduplicates');
    $fresh = $repository->grant($grant['id']);
    $check($fresh !== null && $fresh['token_hash'] === $grant['token_hash'], 'grant hash round trip');
    $fresh['challenge_hash'] = 'hash';
    $check($repository->updateGrant($fresh, 0), 'first grant CAS succeeds');
    $check(!$repository->updateGrant($fresh, 0), 'stale grant CAS fails');
    $repository->revokeForUpload('upload', 'new-event', $now + 10);
    $revoked = $repository->grant($grant['id']);
    $check($revoked['revoked_at'] === $now + 10 && $revoked['revision'] === 2, 'bulk revocation changes revision');
    $check(!$repository->updateGrant($fresh, 1), 'stale update cannot resurrect revoked grant');

    $key = hash('sha256', 'one-throttle');
    $check($repository->consumeThrottle($key, $now, 3, 60), 'throttle first allowed');
    $check($repository->consumeThrottle($key, $now + 1, 3, 60), 'throttle second allowed');
    $check($repository->consumeThrottle($key, $now + 2, 3, 60), 'throttle third allowed');
    $check(!$repository->consumeThrottle($key, $now + 3, 3, 60), 'throttle fourth denied');
    $check(!$repository->consumeThrottle($key, $now + 4, 3, 60), 'throttle remains denied');
    $check($repository->consumeThrottle($key, $now + 60, 3, 60), 'throttle resets at window boundary');

    echo json_encode(['suite' => 'notifications-database', 'checks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
} finally {
    foreach (['clamav_outbox', 'clamav_grants', 'clamav_notification_throttles'] as $table) {
        $db->query('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
    }
}

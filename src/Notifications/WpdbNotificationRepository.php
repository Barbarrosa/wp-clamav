<?php
declare(strict_types=1);

namespace WPClamAV\Notifications;

use RuntimeException;

final class WpdbNotificationRepository implements NotificationRepository
{
    private string $outbox;
    private string $grants;
    private string $throttles;

    public function __construct(private object $db)
    {
        $prefix = (string) $db->prefix;
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('Invalid database prefix.');
        }
        $this->outbox = $prefix . 'clamav_outbox';
        $this->grants = $prefix . 'clamav_grants';
        $this->throttles = $prefix . 'clamav_notification_throttles';
    }

    public function install(): void
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        foreach ($this->schema() as $sql) {
            dbDelta($sql);
        }
    }

    public function schema(): array
    {
        $collation = method_exists($this->db, 'get_charset_collate') ? $this->db->get_charset_collate() : '';
        return [
            "CREATE TABLE {$this->outbox} (
                id varchar(32) NOT NULL,
                delivery_key char(64) NOT NULL,
                kind varchar(16) NOT NULL,
                state varchar(16) NOT NULL,
                payload longtext NOT NULL,
                attempts int unsigned NOT NULL DEFAULT 0,
                next_at bigint unsigned NOT NULL,
                lease_until bigint unsigned NOT NULL DEFAULT 0,
                lease_token varchar(32) NOT NULL DEFAULT '',
                last_error varchar(512) NOT NULL DEFAULT '',
                created_at bigint unsigned NOT NULL,
                updated_at bigint unsigned NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY delivery_key (delivery_key),
                KEY due (state,next_at),
                KEY leases (state,lease_until)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$this->grants} (
                id varchar(32) NOT NULL,
                upload_id varchar(128) NOT NULL,
                event_id varchar(128) NOT NULL,
                expires_at bigint unsigned NOT NULL,
                revoked_at bigint unsigned NOT NULL DEFAULT 0,
                revision bigint unsigned NOT NULL DEFAULT 0,
                payload longtext NOT NULL,
                PRIMARY KEY  (id),
                KEY upload_event (upload_id,event_id),
                KEY expiry (expires_at)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$this->throttles} (
                throttle_key char(64) NOT NULL,
                window_start bigint unsigned NOT NULL,
                counter bigint unsigned NOT NULL,
                PRIMARY KEY  (throttle_key)
            ) ENGINE=InnoDB {$collation};",
        ];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function rows(string $sql): array
    {
        $rows = $this->db->get_results($sql, 'ARRAY_A');
        if (!is_array($rows)) {
            throw new RuntimeException('Unable to read notification state.');
        }
        return $rows;
    }

    private function decodeDelivery(array $row): array
    {
        $row['payload'] = json_decode((string) $row['payload'], true, 64, JSON_THROW_ON_ERROR);
        foreach (['attempts', 'next_at', 'lease_until', 'created_at', 'updated_at'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        return $row;
    }

    public function enqueue(array $row): string
    {
        $result = $this->db->query($this->db->prepare(
            "INSERT IGNORE INTO {$this->outbox} (id,delivery_key,kind,state,payload,attempts,next_at,lease_until,last_error,created_at,updated_at) VALUES (%s,%s,%s,'queued',%s,0,%d,0,'',%d,%d)",
            $row['id'], $row['delivery_key'], $row['kind'], $this->json($row['payload']), $row['next_at'], $row['created_at'], $row['created_at']
        ));
        if ($result === false) {
            throw new RuntimeException('Unable to persist notification.');
        }
        $id = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->outbox} WHERE delivery_key=%s", $row['delivery_key']));
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('Notification was not durably queued.');
        }
        return $id;
    }

    public function claim(int $now, int $leaseSeconds, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->rows($this->db->prepare(
            "SELECT * FROM {$this->outbox} WHERE ((state IN ('queued','retry') AND next_at<=%d) OR (state='sending' AND lease_until<=%d)) ORDER BY created_at,id LIMIT %d", $now, $now, $limit
        ));
        $claimed = [];
        foreach ($rows as $row) {
            $leaseToken = bin2hex(random_bytes(16));
            $changed = $this->db->query($this->db->prepare(
                "UPDATE {$this->outbox} SET state='sending',lease_until=%d,lease_token=%s,updated_at=%d WHERE id=%s AND ((state IN ('queued','retry') AND next_at<=%d) OR (state='sending' AND lease_until<=%d))",
                $now + $leaseSeconds, $leaseToken, $now, $row['id'], $now, $now
            ));
            if ($changed === 1) {
                $row['state'] = 'sending';
                $row['lease_until'] = $now + $leaseSeconds;
                $row['lease_token'] = $leaseToken;
                $claimed[] = $this->decodeDelivery($row);
            }
        }
        return $claimed;
    }

    public function finish(string $id, string $leaseToken, string $state, int $now, int $nextAt, string $error, bool $attempted): void
    {
        if (!in_array($state, ['accepted', 'retry', 'queued', 'failed', 'cancelled'], true)) {
            throw new RuntimeException('Invalid delivery state.');
        }
        $result = $this->db->query($this->db->prepare(
            "UPDATE {$this->outbox} SET state=%s,attempts=attempts+%d,next_at=%d,lease_until=0,lease_token='',last_error=%s,updated_at=%d WHERE id=%s AND state='sending' AND lease_token=%s",
            $state, $attempted ? 1 : 0, $nextAt, substr($error, 0, 512), $now, $id, $leaseToken
        ));
        if ($result === false) {
            throw new RuntimeException('Unable to update notification state.');
        }
    }

    public function delivery(string $id): ?array
    {
        $rows = $this->rows($this->db->prepare("SELECT * FROM {$this->outbox} WHERE id=%s", $id));
        return isset($rows[0]) ? $this->decodeDelivery($rows[0]) : null;
    }

    public function createGrant(array $grant): bool
    {
        $result = $this->db->query($this->db->prepare(
            "INSERT IGNORE INTO {$this->grants} (id,upload_id,event_id,expires_at,revoked_at,revision,payload) VALUES (%s,%s,%s,%d,0,0,%s)",
            $grant['id'], $grant['upload_id'], $grant['event_id'], $grant['expires_at'], $this->json($grant)
        ));
        if ($result === false) {
            throw new RuntimeException('Unable to persist download grant.');
        }
        return $result === 1;
    }

    public function grant(string $id): ?array
    {
        $rows = $this->rows($this->db->prepare("SELECT * FROM {$this->grants} WHERE id=%s", $id));
        if (!isset($rows[0])) {
            return null;
        }
        $row = $rows[0];
        $value = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR);
        foreach (['revision', 'revoked_at', 'expires_at'] as $key) {
            $value[$key] = (int) $row[$key];
        }
        return $value;
    }

    public function updateGrant(array $grant, int $expectedRevision): bool
    {
        $grant['revision'] = $expectedRevision + 1;
        $result = $this->db->query($this->db->prepare(
            "UPDATE {$this->grants} SET payload=%s,expires_at=%d,revoked_at=%d,revision=revision+1 WHERE id=%s AND revision=%d",
            $this->json($grant), $grant['expires_at'], $grant['revoked_at'] ?? 0, $grant['id'], $expectedRevision
        ));
        if ($result === false) {
            throw new RuntimeException('Unable to update download grant.');
        }
        return $result === 1;
    }

    public function revokeForUpload(string $uploadId, string $exceptEventId, int $now): void
    {
        $result = $this->db->query($this->db->prepare(
            "UPDATE {$this->grants} SET revoked_at=%d,revision=revision+1 WHERE upload_id=%s AND event_id<>%s AND revoked_at=0", $now, $uploadId, $exceptEventId
        ));
        if ($result === false) {
            throw new RuntimeException('Unable to revoke obsolete download grants.');
        }
    }

    public function consumeThrottle(string $key, int $now, int $limit, int $windowSeconds): bool
    {
        // Assignment order matters: counter observes the old window before it is reset.
        $result = $this->db->query($this->db->prepare(
            "INSERT INTO {$this->throttles} (throttle_key,window_start,counter) VALUES (%s,%d,1)
             ON DUPLICATE KEY UPDATE counter=IF(window_start+%d<=%d,1,LEAST(counter+1,%d)),window_start=IF(window_start+%d<=%d,%d,window_start)",
            $key, $now, $windowSeconds, $now, $limit + 1, $windowSeconds, $now, $now
        ));
        if ($result === false) { throw new RuntimeException('Unable to persist verification throttle.'); }
        $counter = $this->db->get_var($this->db->prepare("SELECT counter FROM {$this->throttles} WHERE throttle_key=%s", $key));
        return $counter !== null && (int) $counter <= $limit;
    }

    public function summary(int $now): array
    {
        $counts = [];
        foreach ($this->rows("SELECT state,COUNT(*) AS total FROM {$this->outbox} GROUP BY state") as $row) {
            $counts[$row['state']] = (int) $row['total'];
        }
        $expired = $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->outbox} WHERE state='sending' AND lease_until<=%d", $now));
        $next = $this->db->get_var("SELECT MIN(next_at) FROM {$this->outbox} WHERE state IN ('queued','retry')");
        return ['states' => $counts, 'expired_leases' => (int) $expired, 'next_attempt_at' => $next === null ? null : (int) $next,
            'accepted_means' => 'Mail transport accepted the request; delivery is not confirmed.'];
    }
}

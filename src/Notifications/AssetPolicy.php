<?php
declare(strict_types=1);

namespace WPClamAV\Notifications;

final class AssetPolicy
{
    private static function incomplete(array $event): bool
    {
        $reasons = $event['reason_codes'] ?? [];
        if (!is_array($reasons)) { return true; }
        foreach ($reasons as $reason) {
            if (!is_string($reason) || in_array($reason, ['scan-limit-encryption-or-parser-alert', 'parser-error',
                'encrypted-content', 'unknown-or-unsupported-layer'], true)) { return true; }
        }
        return false;
    }

    /** A review download is not an upload-publication decision. */
    public static function eligible(array $event, array $receipt, ?array $latest): bool
    {
        $eventId = (string) ($event['id'] ?? $event['event_id'] ?? '');
        $latestId = (string) ($latest['id'] ?? $latest['event_id'] ?? '');
        $status = (string) ($event['status'] ?? 'failed');
        return $eventId !== ''
            && in_array($status, ['clean', 'detected', 'held'], true)
            && ($receipt['complete'] ?? false) === true
            && !isset($receipt['deleted_at'])
            && ($status !== 'clean' || (($event['applicability_proven'] ?? false) === true
                && ($event['coverage_qualified'] ?? false) === true))
            && ($event['completed'] ?? false) === true
            && !self::incomplete($event)
            && $latest !== null
            && $latestId === $eventId
            && ($latest['completed'] ?? false) === true
            && !self::incomplete($latest)
            && in_array((string) ($latest['status'] ?? ''), ['clean', 'detected', 'held'], true)
            && preg_match('/\A[0-9a-f]{64}\z/D', (string) ($event['sha256'] ?? '')) === 1
            && hash_equals((string) $event['sha256'], (string) ($receipt['sha256'] ?? ''))
            && hash_equals((string) $event['sha256'], (string) ($latest['sha256'] ?? ''))
            && isset($event['size'], $receipt['size'])
            && is_int($event['size']) && $event['size'] >= 0
            && $event['size'] === (int) $receipt['size'];
    }
}

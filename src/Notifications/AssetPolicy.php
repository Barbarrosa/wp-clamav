<?php
declare(strict_types=1);

namespace WPClamAV\Notifications;

final class AssetPolicy
{
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
            && $latest !== null
            && $latestId === $eventId
            && ($latest['completed'] ?? false) === true
            && in_array((string) ($latest['status'] ?? ''), ['clean', 'detected', 'held'], true)
            && preg_match('/\A[0-9a-f]{64}\z/D', (string) ($event['sha256'] ?? '')) === 1
            && hash_equals((string) $event['sha256'], (string) ($receipt['sha256'] ?? ''))
            && hash_equals((string) $event['sha256'], (string) ($latest['sha256'] ?? ''))
            && isset($event['size'], $receipt['size'])
            && is_int($event['size']) && $event['size'] >= 0
            && $event['size'] === (int) $receipt['size'];
    }
}

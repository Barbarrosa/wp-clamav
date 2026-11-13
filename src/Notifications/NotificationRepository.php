<?php
declare(strict_types=1);

namespace WPClamAV\Notifications;

/** Durable delivery and download-capability state; submission data belongs to the store. */
interface NotificationRepository
{
    /** Insert once by delivery_key. Return the existing/new row identifier. */
    public function enqueue(array $row): string;
    /** Atomically lease due deliveries, including abandoned expired leases. */
    public function claim(int $now, int $leaseSeconds, int $limit): array;
    public function finish(string $id, string $leaseToken, string $state, int $now, int $nextAt, string $error, bool $attempted): void;
    public function delivery(string $id): ?array;
    public function createGrant(array $grant): bool;
    public function grant(string $id): ?array;
    /** Compare-and-swap; the stored revision is advanced on success. */
    public function updateGrant(array $grant, int $expectedRevision): bool;
    public function revokeForUpload(string $uploadId, string $exceptEventId, int $now): void;
    /** Shared security throttle; keys are nonreversible HMACs, not raw email/IP values. */
    public function consumeThrottle(string $key, int $now, int $limit, int $windowSeconds): bool;
    public function summary(int $now): array;
}

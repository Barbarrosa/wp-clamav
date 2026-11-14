<?php
declare(strict_types=1);
namespace WPClamAV\Contracts;

/** Canonical data is retained until an explicit audited deletion. */
interface SubmissionStore
{
    /** @param resource $stream */
    public function ingestStream($stream, array $context = []): array;
    public function get(string $id): array;
    /** @return resource */
    public function open(string $id);
    public function appendEvent(string $id, array $event): array;
    public function latestEvent(string $id): ?array;
    public function event(string $eventId): array;
    public function list(int $limit = 50, int $offset = 0): array;
    public function listEvents(int $limit = 50, int $offset = 0): array;
    public function delete(string $id, int $actor, string $reason): void;
    public function commitSubmission(string $id, array $fields, array $receiptIds = []): void;
    public function submission(string $id): array;
}

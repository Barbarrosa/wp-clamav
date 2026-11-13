<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'WPClamAV\\')) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});

use WPClamAV\Notifications\NotificationRepository;

final class NotificationMemoryRepository implements NotificationRepository
{
    public array $deliveries = [];
    public array $grants = [];
    public array $throttles = [];

    public function enqueue(array $row): string
    {
        foreach ($this->deliveries as $existing) {
            if ($existing['delivery_key'] === $row['delivery_key']) { return $existing['id']; }
        }
        $this->deliveries[$row['id']] = $row + ['state' => 'queued', 'attempts' => 0, 'lease_until' => 0, 'lease_token' => '', 'last_error' => ''];
        return $row['id'];
    }

    public function claim(int $now, int $leaseSeconds, int $limit): array
    {
        $result = [];
        foreach ($this->deliveries as &$row) {
            if ((in_array($row['state'], ['queued', 'retry'], true) && $row['next_at'] <= $now)
                || ($row['state'] === 'sending' && $row['lease_until'] <= $now)) {
                $row['state'] = 'sending'; $row['lease_until'] = $now + $leaseSeconds; $row['lease_token'] = bin2hex(random_bytes(16));
                $result[] = $row;
                if (count($result) >= $limit) { break; }
            }
        }
        return $result;
    }

    public function finish(string $id, string $leaseToken, string $state, int $now, int $nextAt, string $error, bool $attempted): void
    {
        if ($this->deliveries[$id]['state'] !== 'sending' || $this->deliveries[$id]['lease_token'] !== $leaseToken) { return; }
        $row = &$this->deliveries[$id]; $row['state'] = $state; $row['next_at'] = $nextAt;
        $row['last_error'] = $error; $row['attempts'] += $attempted ? 1 : 0; $row['lease_until'] = 0; $row['lease_token'] = '';
    }
    public function delivery(string $id): ?array { return $this->deliveries[$id] ?? null; }
    public function createGrant(array $grant): bool
    {
        if (isset($this->grants[$grant['id']])) { return false; }
        $this->grants[$grant['id']] = $grant;
        return true;
    }
    public function grant(string $id): ?array { return $this->grants[$id] ?? null; }
    public function updateGrant(array $grant, int $expectedRevision): bool
    {
        if (($this->grants[$grant['id']]['revision'] ?? -1) !== $expectedRevision) { return false; }
        $grant['revision'] = $expectedRevision + 1; $this->grants[$grant['id']] = $grant;
        return true;
    }
    public function revokeForUpload(string $uploadId, string $exceptEventId, int $now): void
    {
        foreach ($this->grants as &$grant) {
            if ($grant['upload_id'] === $uploadId && $grant['event_id'] !== $exceptEventId && $grant['revoked_at'] === 0) {
                $grant['revoked_at'] = $now; ++$grant['revision'];
            }
        }
    }
    public function consumeThrottle(string $key, int $now, int $limit, int $windowSeconds): bool
    {
        if (!isset($this->throttles[$key]) || $this->throttles[$key]['start'] + $windowSeconds <= $now) {
            $this->throttles[$key] = ['start' => $now, 'count' => 0];
        }
        return ++$this->throttles[$key]['count'] <= $limit;
    }
    public function summary(int $now): array
    {
        $states = []; $expired = 0; $next = null;
        foreach ($this->deliveries as $row) {
            $states[$row['state']] = ($states[$row['state']] ?? 0) + 1;
            if ($row['state'] === 'sending' && $row['lease_until'] <= $now) { ++$expired; }
            if (in_array($row['state'], ['queued', 'retry'], true)) { $next = $next === null ? $row['next_at'] : min($next, $row['next_at']); }
        }
        return ['states' => $states, 'expired_leases' => $expired, 'next_attempt_at' => $next];
    }
}

final class NotificationMemoryStore
{
    public array $receipts = [];
    public array $events = [];
    public int $opens = 0;
    public string $path;
    public function __construct(public string $directory)
    {
        $this->path = $directory . '/original';
        file_put_contents($this->path, 'harmless original bytes');
    }
    public function add(string $status = 'clean', bool $completed = true): array
    {
        $uploadId = '11111111111111111111111111111111';
        $event = ['id' => bin2hex(random_bytes(16)), 'upload_id' => $uploadId, 'status' => $status,
            'completed' => $completed, 'applicability_proven' => $status === 'clean', 'coverage_qualified' => $status === 'clean',
            'sha256' => hash_file('sha256', $this->path), 'size' => filesize($this->path), 'name' => 'test-upload.txt',
            'findings' => $status === 'detected' ? ['Test.Finding'] : [], 'reason_codes' => $status === 'failed' ? ['worker_failed'] : [],
            'engine_version' => 'fixture-engine', 'generation_id' => 'fixture-generation',
            'effective_profile' => 'full', 'created_at' => 1700000000];
        $this->events[$event['id']] = $event;
        $this->receipts[$uploadId] = ['id' => $uploadId, 'complete' => true, 'sha256' => $event['sha256'], 'size' => $event['size'],
            'latest_event_id' => $event['id'], 'context' => ['form_id' => 42]];
        return $event;
    }
    public function get(string $id): array { return $this->receipts[$id]; }
    public function event(string $id): array { return $this->events[$id]; }
    public function latestEvent(string $id): ?array { return $this->events[$this->receipts[$id]['latest_event_id']] ?? null; }
    public function open(string $id)
    {
        ++$this->opens;
        $stream = @fopen($this->path, 'rb');
        if ($stream === false) { throw new RuntimeException('missing original'); }
        return $stream;
    }
}

<?php
declare(strict_types=1);
namespace WPClamAV\Scanning;

use WPClamAV\Contracts\SubmissionStore;
use WPClamAV\Storage\Files;

/** A bounded dispatch index; accepted originals and canonical events remain independent. */
final class DurableScanQueue
{
    public const MAX_REQUESTS = 4096; // Internal index format policy, not a hosting entitlement.
    private string $root;
    private string $path;
    private string $requests;

    public function __construct(private SubmissionStore $store)
    {
        if (!method_exists($store, 'directory') || !method_exists($store, 'withLock')) {
            throw new \RuntimeException('The storage provider must support durable bounded dispatch.');
        }
        $this->root = $store->directory();
        $this->path = $this->root . '/scan-queue.json';
        $this->requests = $this->root . '/scan-requests';
        if (!is_dir($this->requests) && !Files::track($this->requests, fn(): bool => mkdir($this->requests, 0700))) {
            throw new \RuntimeException('Cannot create the private scan request directory.');
        }
        Files::privateDirectory($this->requests);
    }

    private function marker(string $id): string { return $this->requests . '/' . Files::id($id) . '.json'; }
    private function save(array $state): void { Files::json($this->path, $state); }

    private function request(array $request, string $id): array
    {
        if (($request['schema'] ?? null) !== 1 || ($request['id'] ?? null) !== $id
            || !is_string($request['revision'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/D', $request['revision'])
            || !in_array($request['profile'] ?? null, ['full', 'pdf-jpeg-png-pe-exclusions'], true)
            || !is_bool($request['explicit'] ?? null) || !is_int($request['requested_at'] ?? null)
            || !is_int($request['next_at'] ?? null) || $request['next_at'] < 0
            || !is_int($request['attempts'] ?? null) || $request['attempts'] < 0
            || !array_key_exists('actor', $request) || !array_key_exists('submission_id', $request)
            || $request['actor'] !== null && (!is_int($request['actor']) || $request['actor'] < 1)
            || $request['explicit'] && $request['actor'] === null
            || $request['submission_id'] !== null && (!is_string($request['submission_id'])
                || !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $request['submission_id']))) {
            throw new \RuntimeException('Scan request state is invalid; no native work was started.');
        }
        return $request;
    }

    private function load(): array
    {
        $state = file_exists($this->path) ? Files::readJson($this->path)
            : ['schema' => 1, 'ids' => [], 'cursor' => 0, 'not_before' => 0, 'journal' => null];
        if (($state['schema'] ?? null) !== 1 || !is_array($state['ids'] ?? null) || !array_is_list($state['ids'])
            || count($state['ids']) > self::MAX_REQUESTS || count(array_unique($state['ids'])) !== count($state['ids'])
            || !is_int($state['cursor'] ?? null) || $state['cursor'] < 0 || $state['cursor'] > count($state['ids'])
            || !is_int($state['not_before'] ?? null) || $state['not_before'] < 0 || !array_key_exists('journal', $state)) {
            throw new \RuntimeException('Scan queue index is invalid; automatic work remains held.');
        }
        foreach ($state['ids'] as $id) { if (!is_string($id)) { throw new \RuntimeException('Invalid queue ID.'); } Files::id($id); }
        if ($state['journal'] !== null) {
            $journal = $state['journal'];
            if (!is_array($journal) || !is_string($journal['id'] ?? null)) { throw new \RuntimeException('Invalid scan queue journal.'); }
            $id = Files::id($journal['id']);
            if (($journal['operation'] ?? null) === 'write' && in_array($id, $state['ids'], true)) {
                $request = $this->request($journal['request'] ?? [], $id);
                Files::json($this->marker($id), $request);
            } elseif (($journal['operation'] ?? null) === 'delete' && !in_array($id, $state['ids'], true)) {
                $this->removeMarker($id);
            } else { throw new \RuntimeException('Invalid scan queue journal operation.'); }
            $state['journal'] = null;
            $this->save($state);
        }
        return $state;
    }

    private function write(array &$state, array $request): void
    {
        $state['journal'] = ['operation' => 'write', 'id' => $request['id'], 'request' => $request];
        $this->save($state); // Index intent precedes its marker, including after a crash.
        Files::json($this->marker($request['id']), $request);
        $state['journal'] = null;
        $this->save($state);
    }

    private function removeMarker(string $id): void
    {
        $path = $this->marker($id);
        if (file_exists($path)) {
            Files::regular($path);
            if (!Files::track($path, static fn(): bool => unlink($path))) { throw new \RuntimeException('Cannot retire a completed dispatch marker.'); }
            Files::syncDirectory($this->requests);
        }
    }

    public function enqueue(string $id, string $profile, bool $explicit, ?int $actor, ?string $submissionId, int $now, ?int $notBefore = null): array
    {
        Files::id($id);
        $request = $this->request(['schema' => 1, 'id' => $id, 'revision' => bin2hex(random_bytes(16)),
            'profile' => $profile, 'explicit' => $explicit, 'actor' => $actor, 'submission_id' => $submissionId,
            'requested_at' => $now, 'next_at' => max($now, $notBefore ?? $now), 'attempts' => 0], $id);
        return $this->store->withLock('scan-queue', function () use ($id, $request, $explicit): array {
            $state = $this->load();
            $exists = in_array($id, $state['ids'], true);
            if ($exists && !$explicit) {
                $existing = $this->request(Files::readJson($this->marker($id)), $id);
                return ['status' => 'queued', 'duplicate' => true, 'next_at' => max($state['not_before'], $existing['next_at'])];
            }
            if (!$exists && count($state['ids']) >= self::MAX_REQUESTS) { return ['status' => 'capacity', 'next_at' => null]; }
            if (!$exists) { $state['ids'][] = $id; }
            $this->write($state, $request);
            if ($explicit) {
                Files::json($this->root . '/audit/scan-request-' . $request['revision'] . '.json',
                    ['action' => 'explicit_scan_request', 'upload_id' => $id] + $request, true);
            }
            return ['status' => 'queued', 'duplicate' => false, 'next_at' => max($state['not_before'], $request['next_at'])];
        }, true);
    }

    /** Claims are persisted before execution so repeated worker crashes cannot reset attempts. */
    public function claim(int $now, int $inspect, int $maxAttempts): array
    {
        return $this->store->withLock('scan-queue', function () use ($now, $inspect, $maxAttempts): array {
            $state = $this->load(); $count = count($state['ids']);
            if ($count === 0) { return ['request' => null, 'pending' => 0, 'next_at' => null]; }
            if ($state['not_before'] > $now) { return ['request' => null, 'pending' => $count, 'next_at' => $state['not_before']]; }
            $next = null;
            for ($i = 0; $i < min($count, max(1, $inspect)); ++$i) {
                $position = $state['cursor'] % $count; $id = $state['ids'][$position];
                $state['cursor'] = ($position + 1) % $count;
                $request = $this->request(Files::readJson($this->marker($id)), $id);
                if ($request['next_at'] > $now) { $next = $next === null ? $request['next_at'] : min($next, $request['next_at']); continue; }
                $exhausted = $request['attempts'] >= $maxAttempts;
                if (!$exhausted) { ++$request['attempts']; }
                $this->write($state, $request);
                return ['request' => $request, 'exhausted' => $exhausted, 'pending' => $count, 'next_at' => $now];
            }
            $this->save($state);
            return ['request' => null, 'pending' => $count, 'next_at' => $next ?? $now + 60];
        }, true);
    }

    public function status(int $now): array
    {
        return $this->store->withLock('scan-queue', function () use ($now): array {
            $state = $this->load(); $count = count($state['ids']);
            return ['pending' => $count, 'next_at' => $count === 0 ? null : max($now + 1, $state['not_before'])];
        }, true);
    }

    /** A capacity refusal is not an execution attempt and pauses the entire dispatch queue. */
    public function finish(array $request, ?int $retryAt = null, bool $capacityDeferred = false): void
    {
        $this->store->withLock('scan-queue', function () use ($request, $retryAt, $capacityDeferred): void {
            $state = $this->load(); $id = Files::id($request['id']); $position = array_search($id, $state['ids'], true);
            if ($position === false) { return; }
            $current = $this->request(Files::readJson($this->marker($id)), $id);
            if ($current['revision'] !== $request['revision']) { return; } // A newer administrator intent survives.
            if ($retryAt !== null) {
                $current['next_at'] = max(0, $retryAt);
                if ($capacityDeferred) {
                    $current['attempts'] = max(0, $current['attempts'] - 1);
                    $state['not_before'] = max($state['not_before'], $current['next_at']);
                }
                $this->write($state, $current);
                return;
            }
            array_splice($state['ids'], $position, 1);
            if ($position < $state['cursor']) { --$state['cursor']; }
            $state['cursor'] = $state['ids'] === [] ? 0 : $state['cursor'] % count($state['ids']);
            $state['journal'] = ['operation' => 'delete', 'id' => $id]; $this->save($state);
            $this->removeMarker($id); $state['journal'] = null; $this->save($state);
        }, true);
    }
}

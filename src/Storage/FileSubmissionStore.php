<?php
declare(strict_types=1);
namespace WPClamAV\Storage;

use WPClamAV\Contracts\SubmissionStore;

/** Files are canonical; an optional WP index only accelerates administration. */
final class FileSubmissionStore implements SubmissionStore
{
    private string $root;
    private ?\Closure $admission;
    private ?object $index;
    private UsageLedger $ledger;

    public function __construct(string $root, array $documentRoots = [], ?callable $admission = null, ?object $index = null)
    {
        if ($root === '' || $root[0] !== '/' || str_contains($root, "\0")) {
            throw new StorageException('Configure an absolute private storage path outside every document root.');
        }
        $parent = realpath(dirname($root));
        if ($parent === false || is_link($root)) { throw new StorageException('Private storage parent must exist and not be a symlink.'); }
        $canonical = $parent . '/' . basename($root);
        foreach ($documentRoots as $doc) {
            $doc = realpath((string) $doc);
            if ($doc !== false && ($canonical === $doc || str_starts_with($canonical . '/', rtrim($doc, '/') . '/'))) {
                throw new StorageException('Private storage must be outside every document root.');
            }
        }
        if (!file_exists($canonical) && !mkdir($canonical, 0700)) { throw new StorageException('Could not create private storage.'); }
        Files::privateDirectory($canonical);
        $this->root = $canonical;
        foreach (['objects', 'receipts', 'events', 'submissions', 'audit', 'scratch', 'locks'] as $directory) {
            if (!file_exists($canonical . '/' . $directory) && !mkdir($canonical . '/' . $directory, 0700)) {
                throw new StorageException('Could not create private storage directory.');
            }
            Files::privateDirectory($canonical . '/' . $directory);
        }
        $this->admission = $admission ? \Closure::fromCallable($admission) : null;
        $this->index = $index;
        $this->ledger = Files::registeredLedger($this->root)
            ?? new UsageLedger($this->root, fn(callable $operation): mixed => $this->withLock('accounting', $operation), $this->admission);
        if ($this->admission !== null) { $this->ledger->setAdmission($this->admission); }
        Files::registerLedger($this->root, $this->ledger);
    }

    public function directory(): string { return $this->root; }
    public function scratchDirectory(): string { return $this->root . '/scratch'; }
    public function withAccounting(callable $operation): mixed { return $this->ledger->run($operation); }

    public function withLock(string $name, callable $operation, bool $nonblocking = false): mixed
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/D', $name)) { throw new StorageException('Invalid lock name.'); }
        $path = $this->root . '/locks/' . $name;
        if (file_exists($path)) { Files::regular($path); }
        $open = static function () use ($path) {
            $existed = file_exists($path);
            $old = umask(0077);
            try { $stream = @fopen($path, 'c+b'); } finally { umask($old); }
            if ($stream !== false && !$existed) { Files::syncDirectory(dirname($path)); }
            return $stream;
        };
        $stream = $name === 'accounting' ? $open() : Files::track($path, $open);
        if ($stream === false) { throw new StorageException('Could not open private lock.'); }
        try {
            Files::regular($path);
            if (!flock($stream, LOCK_EX | ($nonblocking ? LOCK_NB : 0))) { throw new StorageException('Private operation is busy; retry later.'); }
            try { return $operation(); } finally { flock($stream, LOCK_UN); }
        } finally { fclose($stream); }
    }

    public function ingestStream($stream, array $context = []): array
    {
        if (!is_resource($stream)) { throw new StorageException('Upload input is not a readable stream.'); }
        return $this->ledger->run(fn(): array => $this->withLock('admission', function () use ($stream, $context): array {
            $expected = isset($context['size']) ? filter_var($context['size'], FILTER_VALIDATE_INT) : null;
            if ($expected === false || ($expected !== null && $expected < 0)) { throw new StorageException('Invalid upload length.'); }
            $id = bin2hex(random_bytes(16));
            $name = basename(str_replace('\\', '/', (string) ($context['name'] ?? 'upload')));
            $name = substr(preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? 'upload', 0, 240);
            $receipt = ['schema' => 1, 'id' => $id, 'name' => $name ?: 'upload', 'status' => 'held',
                'sha256' => null, 'size' => 0, 'expected_size' => $expected, 'complete' => false,
                'created_at' => time(), 'latest_event_id' => null, 'reason_codes' => ['intake_pending'], 'context' => $context];
            $prototype = array_replace($receipt, ['sha256' => str_repeat('a', 64), 'reason_codes' => ['intake_incomplete']]);
            $recordBytes = strlen(json_encode($prototype, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) + 1;
            if ($recordBytes > 1048576) { throw new StorageException('Upload context exceeds the supported canonical metadata size.'); }
            if ($this->admission !== null) {
                $raw = lstat($this->root . '/receipts');
                $block = max(1, (int) ($raw['blksize'] ?? 4096));
                $overhead = 2 * (int) ceil($recordBytes / $block) * $block;
                if ($expected !== null) { $overhead += (int) ceil($expected / $block) * $block - $expected; }
                ($this->admission)($expected, $this->usage(), array_replace($context,
                    ['storage_overhead_bytes' => $overhead, 'required_inodes' => 3]));
            }
            Files::json($this->root . '/receipts/' . $id . '.json', $receipt, true);
            $objectPath = $this->root . '/objects/' . $id;
            $output = Files::track($objectPath, static function () use ($objectPath) {
                $old = umask(0077);
                try { return @fopen($objectPath, 'xb'); } finally { umask($old); }
            });
            if ($output === false) { throw new StorageException('Could not create private original.', $id); }
            $hash = hash_init('sha256');
            $failure = null;
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false || ($chunk === '' && !feof($stream))) { throw new StorageException('Upload input could not be read.'); }
                    if ($chunk === '') { break; }
                    // Prior chunks are already charged. Rechecking their total would double-count them.
                    if ($this->admission !== null) { ($this->admission)(strlen($chunk), $this->usage(), $context); }
                    if ($expected !== null && $receipt['size'] + strlen($chunk) > $expected) {
                        throw new StorageException('Upload supplied more bytes than its declared length.');
                    }
                    if (isset($context['max_bytes']) && $receipt['size'] + strlen($chunk) > (int) $context['max_bytes']) {
                        throw new StorageException('Upload exceeds the effective intake limit.');
                    }
                    Files::track($objectPath, static fn() => Files::writeAll($output, $chunk));
                    hash_update($hash, $chunk);
                    $receipt['size'] += strlen($chunk);
                }
                if ($expected !== null && $receipt['size'] !== $expected) { throw new StorageException('Upload length does not match the received bytes.'); }
                Files::sync($output);
                $receipt['complete'] = true;
                $receipt['reason_codes'] = ['scan_pending'];
            } catch (\Throwable $error) {
                $failure = $error;
                $receipt['status'] = 'failed';
                $receipt['reason_codes'] = ['intake_incomplete'];
                @fflush($output);
                if (function_exists('fsync')) { @fsync($output); }
            } finally { fclose($output); }
            $receipt['sha256'] = hash_final($hash);
            if (!chmod($this->root . '/objects/' . $id, 0400)) { throw new StorageException('Could not protect private original.', $id); }
            Files::syncDirectory($this->root . '/objects');
            Files::json($this->root . '/receipts/' . $id . '.json', $receipt);
            $this->indexReceipt($receipt);
            if ($failure !== null) { throw new StorageException('Upload was not completely received; private partial receipt ' . $id . ' retained.', $id); }
            return $receipt;
        }));
    }

    public function get(string $id): array
    {
        return Files::readJson($this->root . '/receipts/' . Files::id($id) . '.json');
    }

    public function open(string $id)
    {
        $receipt = $this->get($id);
        if (isset($receipt['deleted_at'])) { throw new StorageException('This original was explicitly deleted.'); }
        $path = $this->root . '/objects/' . Files::id($id);
        $before = Files::regular($path);
        $stream = @fopen($path, 'rb');
        if ($stream === false) { throw new StorageException('Could not open private original.'); }
        $after = fstat($stream);
        if ($after === false || $before['ino'] !== $after['ino'] || $before['dev'] !== $after['dev'] || $after['size'] !== $receipt['size']) {
            fclose($stream);
            throw new StorageException('Private original identity changed.');
        }
        return $stream;
    }

    public function appendEvent(string $id, array $event): array
    {
        return $this->ledger->run(fn(): array => $this->withLock('receipt-' . Files::id($id), function () use ($id, $event): array {
            $receipt = $this->get($id);
            $eventId = bin2hex(random_bytes(16));
            $event = array_merge($event, ['schema' => 1, 'id' => $eventId, 'event_id' => $eventId, 'upload_id' => $id,
                'created_at' => time(), 'name' => $receipt['name'], 'size' => $receipt['size'], 'sha256' => $receipt['sha256']]);
            if (!in_array($event['status'] ?? null, ['clean', 'detected', 'held', 'failed'], true)) { throw new StorageException('Invalid scan event disposition.'); }
            Files::json($this->root . '/events/' . $eventId . '.json', $event, true);
            $receipt['status'] = $event['status'];
            $receipt['latest_event_id'] = $eventId;
            $receipt['reason_codes'] = $event['reason_codes'] ?? [];
            $receipt['manual_approval'] = null;
            Files::json($this->root . '/receipts/' . $id . '.json', $receipt);
            $this->indexReceipt($receipt);
            return $event;
        }));
    }

    public function latestEvent(string $id): ?array
    {
        $receipt = $this->get($id);
        return $receipt['latest_event_id'] === null ? null : $this->event($receipt['latest_event_id']);
    }

    public function event(string $eventId): array { return Files::readJson($this->root . '/events/' . Files::id($eventId) . '.json'); }

    public function list(int $limit = 50, int $offset = 0): array
    {
        $limit = min(100, max(1, $limit)); $offset = max(0, $offset);
        $accounting = file_exists($this->root . '/usage.json') ? Files::readJson($this->root . '/usage.json') : [];
        if ($this->index !== null && !file_exists($this->root . '/.index-dirty.json')
            && !file_exists($this->root . '/.usage-dirty.json') && !($accounting['index_repair_required'] ?? false)) {
            return array_map(fn(string $id): array => $this->get($id), $this->index->ids($limit, $offset));
        }
        $result = []; $position = 0;
        foreach (new \DirectoryIterator($this->root . '/receipts') as $entry) {
            if (!$entry->isFile() || $entry->isLink() || !preg_match('/\A[a-f0-9]{32}\.json\z/D', $entry->getFilename())) { continue; }
            if ($position++ < $offset) { continue; }
            $result[] = Files::readJson($entry->getPathname());
            if (count($result) >= $limit) { break; }
        }
        return $result;
    }

    public function listReceipts(int $limit = 50, int $offset = 0): array { return $this->list($limit, $offset); }

    public function listEvents(int $limit = 50, int $offset = 0): array
    {
        $limit = min(100, max(1, $limit)); $offset = max(0, $offset);
        $result = []; $position = 0;
        foreach (new \DirectoryIterator($this->root . '/events') as $entry) {
            if (!$entry->isFile() || $entry->isLink() || !preg_match('/\A[a-f0-9]{32}\.json\z/D', $entry->getFilename())) { continue; }
            if ($position++ < $offset) { continue; }
            $id = substr($entry->getFilename(), 0, -5);
            $event = $this->event($id);
            if (($event['id'] ?? $event['event_id'] ?? null) !== $id) { throw new StorageException('Canonical scan event identity changed.'); }
            $result[] = $event;
            if (count($result) >= $limit) { break; }
        }
        return $result;
    }

    public function commitSubmission(string $id, array $fields, array $receiptIds = []): void
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $id)) { throw new StorageException('Invalid submission identifier.'); }
        foreach ($receiptIds as $receiptId) { $this->get((string) $receiptId); }
        $this->ledger->run(fn() => $this->withLock('submission-' . substr(hash('sha256', $id), 0, 32), function () use ($id, $fields, $receiptIds): void {
            $path = $this->root . '/submissions/' . $id . '.json';
            $record = ['schema' => 1, 'id' => $id, 'created_at' => time(), 'fields' => $fields, 'receipt_ids' => array_values($receiptIds)];
            if (file_exists($path)) {
                $before = Files::readJson($path);
                if ($before['fields'] !== $fields || $before['receipt_ids'] !== array_values($receiptIds)) {
                    throw new StorageException('A committed submission cannot be replaced; create a revision.');
                }
                return;
            }
            Files::json($path, $record, true);
        }));
    }

    public function approve(string $id, int $actor, string $reason): array
    {
        if ($actor < 1 || trim($reason) === '') { throw new StorageException('An authorized actor and approval reason are required.'); }
        return $this->ledger->run(fn(): array => $this->withLock('receipt-' . Files::id($id), function () use ($id, $actor, $reason): array {
            $receipt = $this->get($id);
            if (!$receipt['complete'] || isset($receipt['deleted_at'])) { throw new StorageException('Incomplete or deleted originals cannot be approved.'); }
            $receipt['manual_approval'] = ['actor' => $actor, 'reason' => $reason, 'created_at' => time(), 'sha256' => $receipt['sha256'], 'event_id' => $receipt['latest_event_id']];
            Files::json($this->root . '/audit/' . bin2hex(random_bytes(16)) . '.json', ['action' => 'manual_approval', 'upload_id' => $id] + $receipt['manual_approval'], true);
            Files::json($this->root . '/receipts/' . $id . '.json', $receipt);
            $this->indexReceipt($receipt);
            return $receipt;
        }));
    }

    public function delete(string $id, int $actor, string $reason): void
    {
        if ($actor < 1 || trim($reason) === '') { throw new StorageException('Explicit deletion needs an authorized actor and reason.'); }
        $this->ledger->run(fn() => $this->withLock('admission', function () use ($id, $actor, $reason): void {
            $this->withLock('receipt-' . Files::id($id), function () use ($id, $actor, $reason): void {
                $receipt = $this->get($id);
                if (isset($receipt['deleted_at'])) { return; }
                Files::json($this->root . '/audit/' . bin2hex(random_bytes(16)) . '.json', ['action' => 'explicit_delete', 'upload_id' => $id, 'actor' => $actor, 'reason' => $reason, 'created_at' => time()], true);
                $path = $this->root . '/objects/' . $id;
                Files::regular($path);
                if (!Files::track($path, static fn(): bool => unlink($path))) { throw new StorageException('Could not delete original.'); }
                Files::syncDirectory($this->root . '/objects');
                $receipt['deleted_at'] = time(); $receipt['manual_approval'] = null;
                Files::json($this->root . '/receipts/' . $id . '.json', $receipt);
                $this->indexReceipt($receipt);
            });
        }));
    }

    public function usage(): array
    {
        return $this->ledger->usage();
    }

    public function reconcileUsage(int $maxEntries = 10000, float $seconds = 2.0): array
    {
        return $this->ledger->reconcile($maxEntries, $seconds);
    }

    private function indexReceipt(array $receipt): void
    {
        if ($this->index === null) { return; }
        try { $this->index->upsert($receipt); }
        catch (\Throwable) {
            // Canonical persistence is authoritative. A failed acceleration index must not hide originals.
            Files::json($this->root . '/.index-dirty.json', ['schema' => 1, 'repair_required' => true, 'observed_at' => time()]);
        }
    }
}

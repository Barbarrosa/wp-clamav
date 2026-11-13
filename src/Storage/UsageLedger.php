<?php
declare(strict_types=1);
namespace WPClamAV\Storage;

/**
 * Crash-safe retained-storage accounting. A dirty ledger is never trusted for admission.
 * Scratch is transient and must have its own resource reservation; it is not retained data.
 */
final class UsageLedger
{
    private \Closure $lock;
    private ?\Closure $admission;
    private int $depth = 0;
    private ?array $working = null;
    private string $snapshot;
    private string $dirty;
    private string $checkpoint;

    public function __construct(private string $root, callable $lock, ?callable $admission = null)
    {
        $this->lock = \Closure::fromCallable($lock);
        $this->admission = $admission === null ? null : \Closure::fromCallable($admission);
        $this->snapshot = $root . '/usage.json';
        $this->dirty = $root . '/.usage-dirty.json';
        $this->checkpoint = $root . '/.usage-reconcile.json';
    }

    public function controlPath(string $path): bool
    {
        return in_array($path, [$this->snapshot, $this->dirty, $this->checkpoint, $this->root . '/locks/accounting'], true)
            || str_starts_with($path, $this->root . '/.usage-record-')
            || $this->separatelyReserved($path);
    }

    private function separatelyReserved(string $path): bool
    {
        foreach (['scratch', 'programs', 'native-state'] as $directory) {
            if ($path === $this->root . '/' . $directory || str_starts_with($path, $this->root . '/' . $directory . '/')) { return true; }
        }
        return false;
    }

    public function active(): bool { return $this->depth > 0; }
    public function setAdmission(callable $admission): void { $this->admission = \Closure::fromCallable($admission); }

    private function stat(string $path): ?array
    {
        clearstatcache(true, $path);
        $value = @lstat($path);
        if ($value === false) { return null; }
        $kind = $value['mode'] & 0170000;
        if (!in_array($kind, [0040000, 0100000], true) || ($kind === 0100000 && $value['nlink'] !== 1)) {
            throw new StorageException('Unsafe entry in private storage; accounting cannot be certified.');
        }
        return ['logical' => (int) $value['size'], 'allocated' => isset($value['blocks']) ? (int) $value['blocks'] * 512 : null,
            'inode' => (int) $value['ino'], 'device' => (int) $value['dev'], 'mtime' => (int) $value['mtime'], 'ctime' => (int) $value['ctime']];
    }

    private function add(array &$usage, ?array $stat, int $sign): void
    {
        if ($stat === null) { return; }
        $usage['logical_bytes'] += $sign * $stat['logical'];
        if ($usage['allocated_bytes'] !== null) {
            $usage['allocated_bytes'] = $stat['allocated'] === null ? null : $usage['allocated_bytes'] + $sign * $stat['allocated'];
        }
        $usage['objects'] += $sign;
        $usage['bytes'] = max($usage['logical_bytes'], $usage['allocated_bytes'] ?? 0);
    }

    private function changed(array &$usage, ?array $before, ?array $after): void
    {
        $this->add($usage, $before, -1);
        $this->add($usage, $after, 1);
    }

    public function mutate(string $path, callable $operation, ?int $newMetadataBytes = null): mixed
    {
        if ($this->controlPath($path)) { return $operation(); }
        return $this->run(function () use ($path, $operation, $newMetadataBytes): mixed {
            $before = $this->stat($path);
            $parent = dirname($path);
            $parentBefore = $this->stat($parent);
            if ($newMetadataBytes !== null && $this->admission !== null && $path !== $this->root . '/quota-snapshot.json') {
                $raw = @lstat($parent);
                $block = max(1, (int) ($raw['blksize'] ?? 4096));
                $charge = (int) ceil($newMetadataBytes / $block) * $block;
                $control = $parent === $this->root && in_array(basename($path), ['cpu-budget.json', 'mail-budget.json', 'worker-cursor.json', 'notification-cursor.json'], true);
                ($this->admission)(0, $this->working, ['storage_operation' => 'metadata', 'control_metadata' => $control,
                    'storage_overhead_bytes' => $charge, 'required_inodes' => 1]);
            }
            try { return $operation(); }
            finally {
                $this->changed($this->working, $before, $this->stat($path));
                $this->changed($this->working, $parentBefore, $this->stat($parent));
            }
        });
    }

    public function run(callable $operation): mixed
    {
        if ($this->depth > 0) { return $operation(); }
        return ($this->lock)(function () use ($operation): mixed {
            $this->working = $this->trusted();
            $beforeRoot = $this->stat($this->root);
            Files::json($this->dirty, ['schema' => 1, 'started_at' => time(), 'pid' => getmypid()], true);
            $this->add($this->working, $this->stat($this->dirty), 1);
            $this->changed($this->working, $beforeRoot, $this->stat($this->root));
            ++$this->depth;
            try {
                $result = $operation();
                --$this->depth;
                // The snapshot describes the final state, excluding this temporary marker.
                $this->add($this->working, $this->stat($this->dirty), -1);
                $this->publish($this->working);
                $rootBefore = $this->stat($this->root);
                if (!unlink($this->dirty)) { throw new StorageException('Could not finalize storage accounting.'); }
                Files::syncDirectory($this->root);
                $this->changed($this->working, $rootBefore, $this->stat($this->root));
                // This correction only removes conservative control-file overhead; canonical data is unchanged.
                $this->publish($this->working);
                return $result;
            } catch (\Throwable $error) {
                $this->depth = 0;
                // A durable marker survives every interrupted or partially failed canonical mutation.
                throw $error;
            } finally { $this->working = null; }
        });
    }

    public function usage(): array
    {
        if ($this->depth > 0 && $this->working !== null) { return $this->working; }
        return ($this->lock)(fn(): array => $this->trusted());
    }

    private function valid(array $value): bool
    {
        return ($value['schema'] ?? null) === 2 && ($value['complete'] ?? false) === true
            && is_int($value['logical_bytes'] ?? null) && $value['logical_bytes'] >= 0
            && ((is_int($value['allocated_bytes'] ?? null) && $value['allocated_bytes'] >= 0)
                || array_key_exists('allocated_bytes', $value) && $value['allocated_bytes'] === null)
            && is_int($value['objects'] ?? null) && $value['objects'] >= 0
            && ($value['bytes'] ?? -1) === max($value['logical_bytes'], $value['allocated_bytes'] ?? 0);
    }

    private function trusted(): array
    {
        $this->recoverControlTemps();
        if (is_file($this->snapshot) && !file_exists($this->dirty) && !file_exists($this->checkpoint)) {
            try { $value = Files::readJson($this->snapshot); }
            catch (\JsonException) { $value = []; }
            if ($this->valid($value)) { return $value; }
        }
        return $this->reconcileStep(10000, 2.0);
    }

    private function recoverControlTemps(): void
    {
        foreach (new \DirectoryIterator($this->root) as $entry) {
            if (!preg_match('/\A\.usage-record-[a-f0-9]{32}\z/D', $entry->getFilename())) { continue; }
            Files::regular($entry->getPathname());
            if (!file_exists($this->dirty)) {
                Files::json($this->dirty, ['schema' => 1, 'started_at' => time(), 'reason' => 'interrupted-accounting-publication'], true);
            }
            // Only interrupted ledger snapshots are disposable, never originals or submission records.
            if (!unlink($entry->getPathname())) { throw new StorageException('Could not recover interrupted accounting publication.'); }
            Files::syncDirectory($this->root);
        }
    }

    /** A caller can supply its confirmed worker envelope; no unbounded web-request traversal. */
    public function reconcile(int $maxEntries = 10000, float $seconds = 2.0): array
    {
        if ($maxEntries < 1 || !is_finite($seconds) || $seconds <= 0) { throw new \InvalidArgumentException('Invalid reconciliation budget.'); }
        if ($this->depth > 0) { throw new StorageException('Cannot reconcile during a canonical mutation.'); }
        return ($this->lock)(fn(): array => $this->reconcileStep($maxEntries, $seconds));
    }

    private function publish(array &$value): void
    {
        $value['schema'] = 2;
        $value['complete'] = true;
        $value['observed_at'] = time();
        $value['charge_basis'] = 'maximum of logical and allocated retained bytes';
        $value['separately_reserved'] = ['scratch', 'programs', 'native-state'];
        // Include this ledger's own size. Two iterations normally reach the fixed point.
        for ($i = 0; $i < 8; ++$i) {
            $before = $this->stat($this->snapshot);
            $rootBefore = $this->stat($this->root);
            $counts = [$value['logical_bytes'], $value['allocated_bytes'], $value['objects']];
            Files::json($this->snapshot, $value);
            $this->changed($value, $before, $this->stat($this->snapshot));
            $this->changed($value, $rootBefore, $this->stat($this->root));
            if ($counts === [$value['logical_bytes'], $value['allocated_bytes'], $value['objects']]) { return; }
        }
        throw new StorageException('Storage accounting did not stabilize; reconciliation remains required.');
    }

    private function stampMatches(array $before, ?array $after): bool
    {
        return $after !== null && $before['inode'] === $after['inode'] && $before['device'] === $after['device']
            && $before['mtime'] === $after['mtime'] && $before['ctime'] === $after['ctime'];
    }

    private function validCheckpoint(array $state): bool
    {
        $usage = $state['usage'] ?? null;
        if (($state['schema'] ?? null) !== 1 || !is_array($usage) || !is_array($state['root_stat'] ?? null)
            || !is_array($state['stack'] ?? null) || count($state['stack']) > 64
            || !is_int($usage['logical_bytes'] ?? null) || $usage['logical_bytes'] < 0
            || !is_int($usage['objects'] ?? null) || $usage['objects'] < 1
            || !(is_int($usage['allocated_bytes'] ?? null) && $usage['allocated_bytes'] >= 0
                || array_key_exists('allocated_bytes', $usage) && $usage['allocated_bytes'] === null)
            || ($usage['bytes'] ?? -1) !== max($usage['logical_bytes'], $usage['allocated_bytes'] ?? 0)) { return false; }
        foreach ($state['stack'] as $frame) {
            if (!is_array($frame) || !is_string($frame['relative'] ?? null) || strlen($frame['relative']) > 4096
                || preg_match('/\A[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*\z/D', $frame['relative']) !== 1
                || array_intersect(explode('/', $frame['relative']), ['.', '..']) !== []
                || !is_int($frame['offset'] ?? null) || $frame['offset'] < 0 || $frame['offset'] > $usage['objects'] + 128
                || !is_array($frame['stamp'] ?? null)) { return false; }
            foreach (['inode', 'device', 'mtime', 'ctime'] as $key) {
                if (!is_int($frame['stamp'][$key] ?? null)) { return false; }
            }
        }
        return true;
    }

    private function reconcileStep(int $maxEntries, float $seconds): array
    {
        $start = hrtime(true);
        if (!file_exists($this->dirty)) { Files::json($this->dirty, ['schema' => 1, 'started_at' => time(), 'reason' => 'accounting-recovery'], true); }
        try { $state = file_exists($this->checkpoint) ? Files::readJson($this->checkpoint) : null; }
        catch (\JsonException) { $state = null; }
        if (!is_array($state) || !$this->validCheckpoint($state)) {
            $rootStat = $this->stat($this->root);
            $hasReceipts = false;
            foreach (new \DirectoryIterator($this->root . '/receipts') as $entry) {
                if (!$entry->isDot()) { $hasReceipts = true; break; }
            }
            $state = ['schema' => 1, 'usage' => ['logical_bytes' => 0, 'allocated_bytes' => 0, 'objects' => 0, 'bytes' => 0,
                'index_repair_required' => $hasReceipts],
                'root_stat' => $rootStat, 'stack' => []];
            $this->add($state['usage'], $rootStat, 1);
            // Root contains only a bounded number of control directories/files. Control files are charged later.
            $rootEntries = 0;
            foreach (new \DirectoryIterator($this->root) as $entry) {
                if ($entry->isDot()) { continue; }
                if (++$rootEntries > $maxEntries) { throw new StorageException('Private storage root requires an explicit larger reconciliation budget.'); }
                $path = $entry->getPathname();
                if ($this->controlPath($path)) { continue; }
                $stat = $this->stat($path);
                $this->add($state['usage'], $stat, 1);
                if ($entry->isDir()) { $state['stack'][] = ['relative' => $entry->getFilename(), 'offset' => 0, 'stamp' => $stat]; }
            }
        }
        $processed = 0;
        while ($state['stack'] !== []) {
            $index = count($state['stack']) - 1;
            $frame = &$state['stack'][$index];
            $directory = $this->root . '/' . $frame['relative'];
            if (!$this->stampMatches($frame['stamp'], $this->stat($directory))) {
                // Unexpected external writes invalidate the snapshot. Restart rather than miss data.
                @unlink($this->checkpoint);
                throw new StorageException('Private storage changed during accounting recovery; restart the bounded reconciliation.');
            }
            $iterator = new \DirectoryIterator($directory);
            try { $iterator->seek((int) $frame['offset']); }
            catch (\OutOfBoundsException) { array_pop($state['stack']); unset($frame); continue; }
            $descended = false;
            while ($iterator->valid()) {
                if ($processed >= $maxEntries || (hrtime(true) - $start) / 1e9 >= $seconds) {
                    unset($frame);
                    Files::json($this->checkpoint, $state);
                    throw new StorageException('Storage accounting recovery is checkpointed; retry the bounded worker before admitting more data.');
                }
                $entry = $iterator->current();
                ++$frame['offset']; ++$processed;
                if (!$entry->isDot()) {
                    $path = $entry->getPathname();
                    $stat = $this->stat($path);
                    $this->add($state['usage'], $stat, 1);
                    if ($entry->isDir()) {
                        if (count($state['stack']) >= 64) { throw new StorageException('Private storage nesting exceeds the reconciliation limit.'); }
                        $relative = $frame['relative'] . '/' . $entry->getFilename();
                        unset($frame);
                        $state['stack'][] = ['relative' => $relative, 'offset' => 0, 'stamp' => $stat];
                        $descended = true;
                        break;
                    }
                }
                $iterator->next();
            }
            unset($frame);
            if (!$descended) { array_pop($state['stack']); }
        }
        $usage = $state['usage'];
        $this->changed($usage, $state['root_stat'], $this->stat($this->root));
        $this->add($usage, $this->stat($this->snapshot), 1);
        $this->publish($usage);
        $rootBefore = $this->stat($this->root);
        if (file_exists($this->checkpoint) && !unlink($this->checkpoint)) { throw new StorageException('Could not finalize accounting recovery checkpoint.'); }
        if (!unlink($this->dirty)) { throw new StorageException('Could not finalize accounting recovery.'); }
        Files::syncDirectory($this->root);
        $this->changed($usage, $rootBefore, $this->stat($this->root));
        $this->publish($usage);
        return $usage;
    }
}

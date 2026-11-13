<?php
declare(strict_types=1);
namespace WPClamAV\Resources;

use WPClamAV\Storage\StorageException;

/** Bounded observations of configured operational roots, never retained originals. */
final class OperationalStorage
{
    public const DEFAULT_MAX_ENTRIES = 1000;
    public const DEFAULT_MAX_SECONDS = 0.25;
    private array $paths;
    private \Closure $clock;

    public function __construct(array $paths, array $canonicalRoots = [], private int $maxEntries = self::DEFAULT_MAX_ENTRIES,
        private float $maxSeconds = self::DEFAULT_MAX_SECONDS, ?callable $clock = null)
    {
        if ($maxEntries < 1 || $maxEntries > 100000 || !is_finite($maxSeconds) || $maxSeconds <= 0 || $maxSeconds > 30) {
            throw new \InvalidArgumentException('Invalid operational observation work bound.');
        }
        $this->clock = $clock === null ? static fn(): float => microtime(true) : \Closure::fromCallable($clock);
        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path)) { throw new \InvalidArgumentException('Operational storage roots must be absolute paths.'); }
            $path = self::normalize($path);
            foreach ($canonicalRoots as $canonical) {
                $canonical = self::normalize((string) $canonical);
                if (self::within($canonical, $path)) { throw new StorageException('An operational root cannot contain retained originals.'); }
                if (self::within($path, $canonical)) {
                    $separate = false;
                    foreach (['native-state', 'programs', 'scratch'] as $name) {
                        if (self::within($path, $canonical . '/' . $name)) { $separate = true; break; }
                    }
                    if (!$separate) { throw new StorageException('Operational storage overlaps retained data; use a separately reserved namespace.'); }
                }
            }
            $normalized[$path] = true;
        }
        $roots = array_keys($normalized); sort($roots, SORT_STRING); $this->paths = [];
        foreach ($roots as $path) {
            foreach ($this->paths as $existing) { if (self::within($path, $existing)) { continue 2; } }
            $this->paths[] = $path;
        }
    }

    private static function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path . '/', rtrim($root, '/') . '/');
    }

    private static function normalize(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || strlen($path) > 4096) {
            throw new \InvalidArgumentException('Operational roots require bounded absolute paths.');
        }
        $components = array_values(array_filter(explode('/', $path), static fn($part): bool => $part !== ''));
        if ($components === [] || array_intersect($components, ['.', '..']) !== []) { throw new \InvalidArgumentException('Unsafe operational storage root.'); }
        return '/' . implode('/', $components);
    }

    private function bound(int $entries, float $started): void
    {
        if ($entries > $this->maxEntries || (float) ($this->clock)() - $started > $this->maxSeconds) {
            throw new StorageException('Operational storage observation exceeded its bounded work budget; admission is held until a complete observation is available. No files were deleted.');
        }
    }

    /** Reject symlinks in ancestors too; a missing future directory contributes zero. */
    private function existingRoot(string $root): bool
    {
        $path = '';
        foreach (explode('/', ltrim($root, '/')) as $component) {
            $parent = $path === '' ? '/' : $path;
            $path .= '/' . $component; clearstatcache(true, $path); $stat = @lstat($path);
            if ($stat === false) {
                if (!is_dir($parent) || !is_readable($parent) || !is_executable($parent)) { throw new StorageException('Operational storage availability cannot be observed.'); }
                return false;
            }
            if (($stat['mode'] & 0170000) !== 0040000) { throw new StorageException('Operational roots and their ancestors must be real directories without symlinks.'); }
        }
        return true;
    }

    private static function stat(string $path): array
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        if ($stat === false || !isset($stat['blocks']) || !is_int($stat['blocks']) || $stat['blocks'] < 0
            || !is_int($stat['size']) || $stat['size'] < 0 || $stat['blocks'] > intdiv(PHP_INT_MAX, 512)) {
            throw new StorageException('Logical or allocated operational storage usage is unknown; admission is held.');
        }
        $kind = $stat['mode'] & 0170000;
        if (!in_array($kind, [0040000, 0100000], true) || $kind === 0100000 && $stat['nlink'] !== 1) {
            throw new StorageException('Unsafe symlink, hard link or special entry in operational storage; admission is held.');
        }
        return $stat;
    }

    private static function same(array $first, array $last): bool
    {
        foreach (['dev', 'ino', 'mode', 'size', 'blocks', 'mtime', 'ctime', 'nlink'] as $key) { if ($first[$key] !== $last[$key]) { return false; } }
        return true;
    }

    private static function plus(int $a, int $b): int
    {
        if ($b < 0 || $a > PHP_INT_MAX - $b) { throw new StorageException('Operational storage usage overflows this runtime.'); }
        return $a + $b;
    }

    public function measure(): array
    {
        $started = (float) ($this->clock)(); $entries = 0; $logical = 0; $allocated = 0; $rootUsage = [];
        $visit = function (string $path, int $depth = 0) use (&$visit, &$entries, &$logical, &$allocated, $started): void {
            $this->bound(++$entries, $started);
            if ($depth > 128) { throw new StorageException('Operational storage nesting exceeds its format bound; admission is held.'); }
            $before = self::stat($path);
            $logical = self::plus($logical, $before['size']); $allocated = self::plus($allocated, $before['blocks'] * 512);
            if (($before['mode'] & 0170000) === 0040000) {
                try { $iterator = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS); }
                catch (\Throwable) { throw new StorageException('Operational storage cannot be listed safely.'); }
                $names = hash_init('sha256'); $count = 0;
                foreach ($iterator as $entry) {
                    $name = $entry->getFilename(); hash_update($names, strlen($name) . ':' . $name); ++$count;
                    $visit($path . '/' . $name, $depth + 1);
                }
                $firstNames = hash_final($names); $names = hash_init('sha256'); $again = 0;
                // Verify names even when the filesystem's timestamp granularity is one second.
                foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
                    $this->bound($entries, $started); $name = $entry->getFilename(); hash_update($names, strlen($name) . ':' . $name); ++$again;
                    if ($again > $this->maxEntries) { throw new StorageException('Operational storage changed while observing it; admission is held.'); }
                }
                if ($count !== $again || !hash_equals($firstNames, hash_final($names))) { throw new StorageException('Operational storage changed while observing it; admission is held.'); }
            }
            if (!self::same($before, self::stat($path))) { throw new StorageException('Operational storage changed while observing it; admission is held.'); }
        };
        foreach ($this->paths as $path) {
            $this->bound($entries, $started); $beforeLogical = $logical; $beforeAllocated = $allocated; $beforeEntries = $entries;
            if ($this->existingRoot($path)) { $visit($path); }
            $rootUsage[] = ['path' => $path, 'logical_bytes' => $logical - $beforeLogical,
                'allocated_bytes' => $allocated - $beforeAllocated, 'objects' => $entries - $beforeEntries];
        }
        $this->bound($entries, $started);
        return ['complete' => true, 'bytes' => max($logical, $allocated), 'logical_bytes' => $logical, 'allocated_bytes' => $allocated,
            'objects' => $entries, 'namespace_id' => hash('sha256', json_encode($this->paths, JSON_THROW_ON_ERROR)), 'roots' => $rootUsage,
            'observed_at' => time(), 'policy' => ['max_entries' => $this->maxEntries, 'max_seconds' => $this->maxSeconds],
            'warnings' => ['Operational roots are a bounded observation, not account-wide usage. Concurrent updates still require reservations and monitoring; no automatic purge occurs.']];
    }
}

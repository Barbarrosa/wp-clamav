<?php
declare(strict_types=1);
namespace WPClamAV\Storage;

final class Files
{
    /** @var array<string,UsageLedger> */
    private static array $ledgers = [];

    public static function registeredLedger(string $root): ?UsageLedger
    {
        return self::$ledgers[rtrim($root, '/')] ?? null;
    }

    public static function registerLedger(string $root, UsageLedger $ledger): void
    {
        self::$ledgers[rtrim($root, '/')] = $ledger;
        uksort(self::$ledgers, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    public static function track(string $path, callable $operation, ?int $newMetadataBytes = null): mixed
    {
        foreach (self::$ledgers as $root => $ledger) {
            if (str_starts_with($path, $root . '/') && !$ledger->controlPath($path)) {
                return $ledger->mutate($path, $operation, $newMetadataBytes);
            }
        }
        return $operation();
    }
    public static function id(string $value): string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $value)) {
            throw new StorageException('Invalid receipt identifier.');
        }
        return $value;
    }

    public static function privateDirectory(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_dir($path)) {
            throw new StorageException('Private storage must be a real directory.');
        }
        $s = lstat($path);
        if ($s === false || ($s['mode'] & 0077) !== 0 || !is_writable($path)) {
            throw new StorageException('Private storage must be writable and accessible only to its owner (0700).');
        }
        if (function_exists('posix_geteuid') && $s['uid'] !== posix_geteuid()) {
            throw new StorageException('Private storage must belong to the PHP account.');
        }
    }

    public static function regular(string $path): array
    {
        clearstatcache(true, $path);
        $s = lstat($path);
        if ($s === false || ($s['mode'] & 0170000) !== 0100000 || $s['nlink'] !== 1 || ($s['mode'] & 0077) !== 0) {
            throw new StorageException('Unsafe or missing private file.');
        }
        return $s;
    }

    public static function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $n = fwrite($stream, substr($bytes, $offset));
            if ($n === false || $n === 0) {
                throw new StorageException('Private storage write failed.');
            }
            $offset += $n;
        }
    }

    public static function sync($stream): void
    {
        if (!fflush($stream) || (function_exists('fsync') && !fsync($stream))) {
            throw new StorageException('Private storage could not be persisted.');
        }
    }

    public static function syncDirectory(string $path): void
    {
        if (!function_exists('fsync')) {
            throw new StorageException('PHP fsync support is required for durable intake.');
        }
        $stream = @fopen($path, 'r');
        if ($stream === false) {
            throw new StorageException('Could not open storage directory for persistence.');
        }
        try {
            if (!@fsync($stream)) {
                throw new StorageException('Directory persistence is not supported by this storage provider.');
            }
        } finally {
            fclose($stream);
        }
    }

    public static function json(string $path, array $value, bool $exclusive = false): void
    {
        $data = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (strlen($data) > 1048576) { throw new StorageException('Private record exceeds the supported metadata size; no record was written.'); }
        self::track($path, static function () use ($path, $data, $exclusive): void {
            self::jsonBytes($path, $data, $exclusive);
        }, strlen($data));
    }

    private static function jsonBytes(string $path, string $data, bool $exclusive): void
    {
        if ($exclusive) {
            self::writeNew($path, $data);
            return;
        }
        if (file_exists($path)) {
            self::regular($path);
        }
        $prefix = in_array(basename($path), ['usage.json', '.usage-dirty.json', '.usage-reconcile.json'], true)
            ? '.usage-record-' : '.record-';
        $temp = dirname($path) . '/' . $prefix . bin2hex(random_bytes(16));
        try {
            self::writeNew($temp, $data);
            if (!rename($temp, $path)) {
                throw new StorageException('Could not atomically commit private record.');
            }
            self::syncDirectory(dirname($path));
        } finally {
            if (is_file($temp)) { unlink($temp); }
        }
    }

    private static function writeNew(string $path, string $data): void
    {
        $old = umask(0077);
        try { $stream = @fopen($path, 'xb'); } finally { umask($old); }
        if ($stream === false) {
            throw new StorageException('Could not create exclusive private record.');
        }
        try {
            if (!chmod($path, 0600)) { throw new StorageException('Could not protect private record.'); }
            self::writeAll($stream, $data);
            self::sync($stream);
        } finally { fclose($stream); }
        self::syncDirectory(dirname($path));
    }

    public static function readJson(string $path): array
    {
        $before = self::regular($path);
        if ($before['size'] > 1048576) { throw new StorageException('Private record exceeds the metadata limit.'); }
        $stream = @fopen($path, 'rb');
        if ($stream === false) { throw new StorageException('Could not open private record.'); }
        try {
            $after = fstat($stream);
            if ($after === false || $before['ino'] !== $after['ino'] || $before['dev'] !== $after['dev']) {
                throw new StorageException('Private record changed while opening.');
            }
            $bytes = stream_get_contents($stream, 1048577);
            if ($bytes === false) { throw new StorageException('Could not read private record.'); }
            $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($value)) { throw new StorageException('Invalid private record.'); }
            return $value;
        } finally { fclose($stream); }
    }
}

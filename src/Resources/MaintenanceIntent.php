<?php
declare(strict_types=1);
namespace WPClamAV\Resources;

use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\Files;

/** One expiring priority record; it never substitutes for the live OS dispatch lock. */
final class MaintenanceIntent
{
    public static function signal(FileSubmissionStore $store, int $now, int $seconds): string
    {
        return $store->withLock('maintenance-intent', function () use ($store, $now, $seconds): string {
            $token = bin2hex(random_bytes(16));
            Files::json($store->directory() . '/maintenance-intent.json', ['token' => $token, 'until' => $now + $seconds]);
            return $token;
        });
    }

    public static function until(FileSubmissionStore $store): int
    {
        $path = $store->directory() . '/maintenance-intent.json';
        if (!file_exists($path)) { return 0; }
        $record = Files::readJson($path);
        if (!is_string($record['token'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/D', $record['token'])
            || !is_int($record['until'] ?? null) || $record['until'] < 0 || $record['until'] > 0xffffffff - 60) {
            throw new \RuntimeException('Invalid maintenance priority state.');
        }
        return $record['until'];
    }

    public static function finish(FileSubmissionStore $store, string $token): void
    {
        $store->withLock('maintenance-intent', function () use ($store, $token): void {
            $path = $store->directory() . '/maintenance-intent.json';
            if (!file_exists($path)) { return; }
            $record = Files::readJson($path);
            if (($record['token'] ?? null) === $token) { $record['until'] = 0; Files::json($path, $record); }
        });
    }
}

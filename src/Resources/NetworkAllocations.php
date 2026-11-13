<?php
declare(strict_types=1);
namespace WPClamAV\Resources;

use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\Files;

/** Conservative site shares of confirmed network capacity, never per-site grants. */
final class NetworkAllocations
{
    private const QUOTAS = ['account_remaining_bytes', 'inodes_remaining', 'quota_observed_at'];

    /** @return array{budgets:array,diagnostics:array} */
    public static function allocate(FileSubmissionStore $coordinator, array $budgets, int $siteCount): array
    {
        if ($siteCount < 1) { throw new \InvalidArgumentException('The network site count must be positive.'); }
        $observations = [];
        foreach (self::QUOTAS as $key) { $observations[$key] = self::integer($budgets, $key); }
        $storage = self::integer($budgets, 'storage_bytes');
        return $coordinator->withLock('network-allocation', function () use ($coordinator, $budgets, $siteCount, $observations, $storage): array {
            $path = $coordinator->directory() . '/network-allocation.json';
            $saved = file_exists($path) ? self::checked(Files::readJson($path)) : null;
            $identity = self::identity($observations);
            $reason = null;
            $write = false;
            if ($saved === null) {
                $saved = self::record($observations, $siteCount);
                $write = true;
            } else {
                $changed = !hash_equals($saved['confirmation_sha256'], $identity);
                $fresh = $changed && self::fresh($saved['observations'], $observations);
                if ($fresh) {
                    $saved = self::record($observations, $siteCount);
                    $write = true;
                } elseif ($siteCount !== $saved['confirmed_site_count'] || $changed) {
                    $reason = $siteCount !== $saved['confirmed_site_count']
                        ? 'The network site count changed without a fresh quota confirmation.'
                        : 'The quota confirmation was removed or its observation time regressed.';
                    if (!$saved['held'] || $saved['observed_site_count'] !== $siteCount || $saved['reason'] !== $reason) {
                        $saved['held'] = true;
                        $saved['observed_site_count'] = $siteCount;
                        $saved['reason'] = $reason;
                        $saved['held_at'] = time();
                        $write = true;
                    }
                }
            }
            if ($write) { Files::json($path, $saved); }
            $allocated = $budgets;
            $shares = [];
            foreach (['storage_bytes' => $storage, 'account_remaining_bytes' => $observations['account_remaining_bytes'],
                'inodes_remaining' => $observations['inodes_remaining']] as $key => $value) {
                $share = $value === null ? null : ($saved['held'] ? 0 : intdiv($value, $siteCount));
                $shares[$key] = $share;
                // Absence stays absence. Explicit unknown values remain unknown.
                if (array_key_exists($key, $allocated) && $value !== null) { $allocated[$key] = $share; }
            }
            $warnings = ['Network capacities are divided into conservative per-site shares. Other account activity and operational artifacts still require separate capacity reservations.'];
            if ($saved['held']) {
                $warnings[] = $saved['reason'] . ' Confirm current account remainder/inodes with a newer quota_observed_at before accepting more uploads.';
            }
            if ($observations['account_remaining_bytes'] === null || $observations['inodes_remaining'] === null) {
                $warnings[] = 'An unknown account remainder or inode quota remains unknown; network shares are not an account-wide availability guarantee.';
            }
            return ['budgets' => $allocated, 'diagnostics' => [
                'held' => $saved['held'], 'configured_network_storage_bytes' => $storage,
                'confirmed_network_quotas' => $observations, 'site_count' => $siteCount,
                'confirmed_site_count' => $saved['confirmed_site_count'], 'per_site' => $shares,
                'confirmation_sha256' => $saved['confirmation_sha256'], 'confirmed_at' => $saved['confirmed_at'],
                'source' => 'Configured network storage allocation and confirmed account observations divided by the current WordPress site count.',
                'warnings' => $warnings,
            ]];
        });
    }

    private static function integer(array $values, string $key): ?int
    {
        $value = $values[$key] ?? null;
        if ($value === null || $value === '') { return null; }
        if (is_int($value)) {
            if ($value < 0) { throw new \InvalidArgumentException('Invalid network allocation: ' . $key); }
            return $value;
        }
        if (!is_float($value) || !is_finite($value) || $value < 0 || $value >= PHP_INT_MAX) {
            throw new \InvalidArgumentException('Invalid network allocation: ' . $key);
        }
        return (int) floor($value);
    }

    private static function identity(array $observations): string
    {
        return hash('sha256', json_encode($observations, JSON_THROW_ON_ERROR));
    }

    private static function fresh(array $old, array $new): bool
    {
        $before = $old['quota_observed_at']; $after = $new['quota_observed_at'];
        if ($before !== null && ($after === null || $after < $before)) { return false; }
        if ($after !== null && ($before === null || $after > $before)) { return true; }
        foreach (['account_remaining_bytes', 'inodes_remaining'] as $key) {
            if ($new[$key] !== null && $new[$key] !== $old[$key]) { return true; }
        }
        return false;
    }

    private static function record(array $observations, int $siteCount): array
    {
        return ['schema' => 1, 'observations' => $observations, 'confirmation_sha256' => self::identity($observations),
            'confirmed_site_count' => $siteCount, 'observed_site_count' => $siteCount,
            'held' => false, 'reason' => null, 'confirmed_at' => time()];
    }

    private static function checked(array $saved): array
    {
        if (($saved['schema'] ?? null) !== 1 || !is_array($saved['observations'] ?? null)
            || array_keys($saved['observations']) !== self::QUOTAS || !is_bool($saved['held'] ?? null)
            || !is_int($saved['confirmed_site_count'] ?? null) || $saved['confirmed_site_count'] < 1
            || !is_int($saved['observed_site_count'] ?? null) || $saved['observed_site_count'] < 1
            || !is_int($saved['confirmed_at'] ?? null) || $saved['confirmed_at'] < 0
            || !array_key_exists('reason', $saved) || ($saved['reason'] !== null && !is_string($saved['reason']))
            || ($saved['held'] && $saved['reason'] === null)
            || !is_string($saved['confirmation_sha256'] ?? null)
            || !hash_equals(self::identity($saved['observations']), $saved['confirmation_sha256'])) {
            throw new \RuntimeException('Network allocation state is invalid; a fresh per-site quota was not assumed.');
        }
        foreach (self::QUOTAS as $key) {
            if ($saved['observations'][$key] !== null && (!is_int($saved['observations'][$key]) || $saved['observations'][$key] < 0)) {
                throw new \RuntimeException('Network quota observation is invalid.');
            }
        }
        return $saved;
    }
}

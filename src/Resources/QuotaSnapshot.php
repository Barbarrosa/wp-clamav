<?php
declare(strict_types=1);
namespace WPClamAV\Resources;

use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\Files;

/** Remaining quotas are observations, not reusable grants of free storage per upload. */
final class QuotaSnapshot
{
    public function __construct(private FileSubmissionStore $store, private array $budgets) {}

    private function integer(string $key): ?int
    {
        $value = $this->budgets[$key] ?? null;
        if ($value === null || $value === '') { return null; }
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > PHP_INT_MAX) {
            throw new \InvalidArgumentException('Invalid confirmed quota observation: ' . $key);
        }
        return (int) floor((float) $value);
    }

    public function remaining(array $usage): array
    {
        $confirmed = ['account_remaining_bytes' => $this->integer('account_remaining_bytes'),
            'inodes_remaining' => $this->integer('inodes_remaining'), 'quota_observed_at' => $this->integer('quota_observed_at')];
        if ($confirmed['account_remaining_bytes'] === null && $confirmed['inodes_remaining'] === null) {
            return $confirmed + ['baseline_bytes' => null, 'baseline_inodes' => null,
                'warnings' => ['Account quota observations are unknown; filesystem free space is not an account-quota guarantee.']];
        }
        return $this->store->withAccounting(function () use ($confirmed): array {
            $current = $this->store->usage();
            $identity = hash('sha256', json_encode($confirmed, JSON_THROW_ON_ERROR));
            $path = $this->store->directory() . '/quota-snapshot.json';
            $saved = file_exists($path) ? Files::readJson($path) : null;
            if ($saved !== null && (($saved['schema'] ?? null) !== 1 || !is_array($saved['confirmed'] ?? null)
                || !is_string($saved['identity'] ?? null)
                || !hash_equals(hash('sha256', json_encode($saved['confirmed'], JSON_THROW_ON_ERROR)), $saved['identity'])
                || !is_int($saved['baseline_bytes'] ?? null) || !is_int($saved['baseline_inodes'] ?? null)
                || $saved['baseline_bytes'] < 0 || $saved['baseline_inodes'] < 0)) {
                throw new \RuntimeException('Quota observation state is invalid; a new allocation was not assumed.');
            }
            if ($saved === null || ($saved['identity'] ?? null) !== $identity) {
                $saved = ['schema' => 1, 'identity' => $identity, 'confirmed' => $confirmed,
                    'baseline_bytes' => (int) $current['bytes'], 'baseline_inodes' => (int) $current['objects'], 'recorded_at' => time()];
                Files::json($path, $saved);
            }
            if (($saved['schema'] ?? null) !== 1 || !is_int($saved['baseline_bytes'] ?? null) || !is_int($saved['baseline_inodes'] ?? null)
                || $saved['baseline_bytes'] < 0 || $saved['baseline_inodes'] < 0) {
                throw new \RuntimeException('Quota observation state is invalid; admission remains held.');
            }
            $growthBytes = max(0, (int) $current['bytes'] - $saved['baseline_bytes']);
            $growthInodes = max(0, (int) $current['objects'] - $saved['baseline_inodes']);
            return ['account_remaining_bytes' => $confirmed['account_remaining_bytes'] === null ? null : max(0, $confirmed['account_remaining_bytes'] - $growthBytes),
                'inodes_remaining' => $confirmed['inodes_remaining'] === null ? null : max(0, $confirmed['inodes_remaining'] - $growthInodes),
                'baseline_bytes' => $saved['baseline_bytes'], 'baseline_inodes' => $saved['baseline_inodes'],
                'private_growth_bytes' => $growthBytes, 'private_growth_inodes' => $growthInodes,
                'quota_observed_at' => $confirmed['quota_observed_at'], 'recorded_at' => $saved['recorded_at'],
                'warnings' => ['Confirmed remaining quotas are reduced by subsequent private-store growth. Other account changes require a fresh observation.']];
        });
    }
}

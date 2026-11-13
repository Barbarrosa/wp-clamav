<?php
declare(strict_types=1);
namespace WPClamAV\Resources;

use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\Files;

/** Remaining quotas are observations, not reusable grants of free storage per upload. */
final class QuotaSnapshot
{
    private ?\Closure $supplementalUsage;

    public function __construct(private FileSubmissionStore $store, private array $budgets, ?callable $supplementalUsage = null)
    {
        $this->supplementalUsage = $supplementalUsage === null ? null : \Closure::fromCallable($supplementalUsage);
    }

    private function integer(string $key): ?int
    {
        $value = $this->budgets[$key] ?? null;
        if ($value === null || $value === '') { return null; }
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value >= PHP_INT_MAX) {
            throw new \InvalidArgumentException('Invalid confirmed quota observation: ' . $key);
        }
        return (int) floor((float) $value);
    }

    private function supplemental(): array
    {
        if ($this->supplementalUsage === null) {
            return ['complete' => true, 'bytes' => 0, 'objects' => 0, 'namespace_id' => hash('sha256', '[]')];
        }
        $usage = ($this->supplementalUsage)();
        if (!is_array($usage) || ($usage['complete'] ?? false) !== true || !is_int($usage['bytes'] ?? null)
            || $usage['bytes'] < 0 || !is_int($usage['objects'] ?? null) || $usage['objects'] < 0
            || !is_string($usage['namespace_id'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $usage['namespace_id']) !== 1) {
            throw new \RuntimeException('Operational storage usage is incomplete or unknown; quota admission is held.');
        }
        return $usage;
    }

    private static function sum(int $first, int $second): int
    {
        if ($first < 0 || $second < 0 || $first > PHP_INT_MAX - $second) { throw new \RuntimeException('Combined quota usage cannot be represented.'); }
        return $first + $second;
    }

    public function remaining(array $usage): array
    {
        $confirmed = ['account_remaining_bytes' => $this->integer('account_remaining_bytes'),
            'inodes_remaining' => $this->integer('inodes_remaining'), 'quota_observed_at' => $this->integer('quota_observed_at')];
        return $this->store->withAccounting(function () use ($confirmed): array {
            // The ledger's working view is authoritative while an admission mutation is in progress.
            $canonical = $this->store->usage(); $operational = $this->supplemental();
            $current = ['bytes' => self::sum((int) $canonical['bytes'], $operational['bytes']),
                'objects' => self::sum((int) $canonical['objects'], $operational['objects'])];
            if ($confirmed['account_remaining_bytes'] === null && $confirmed['inodes_remaining'] === null) {
                return $confirmed + ['baseline_bytes' => null, 'baseline_inodes' => null, 'operational_usage' => $operational,
                    'warnings' => ['Account quota observations are unknown; filesystem free space is not an account-quota guarantee.']];
            }
            $identity = hash('sha256', json_encode($confirmed, JSON_THROW_ON_ERROR));
            $path = $this->store->directory() . '/quota-snapshot.json';
            $saved = file_exists($path) ? Files::readJson($path) : null;
            if ($saved !== null && (!in_array($saved['schema'] ?? null, [1, 2], true) || !is_array($saved['confirmed'] ?? null)
                || !is_string($saved['identity'] ?? null)
                || !hash_equals(hash('sha256', json_encode($saved['confirmed'], JSON_THROW_ON_ERROR)), $saved['identity'])
                || !is_int($saved['baseline_bytes'] ?? null) || !is_int($saved['baseline_inodes'] ?? null)
                || $saved['baseline_bytes'] < 0 || $saved['baseline_inodes'] < 0)) {
                throw new \RuntimeException('Quota observation state is invalid; a new allocation was not assumed.');
            }
            if ($saved !== null && $saved['schema'] === 2) {
                foreach (['baseline_canonical_bytes', 'baseline_canonical_inodes', 'baseline_operational_bytes', 'baseline_operational_inodes'] as $key) {
                    if (!is_int($saved[$key] ?? null) || $saved[$key] < 0) { throw new \RuntimeException('Combined quota baseline is invalid; admission remains held.'); }
                }
                if (!is_string($saved['operational_namespace_id'] ?? null)
                    || preg_match('/\A[0-9a-f]{64}\z/D', $saved['operational_namespace_id']) !== 1
                    || self::sum($saved['baseline_canonical_bytes'], $saved['baseline_operational_bytes']) !== $saved['baseline_bytes']
                    || self::sum($saved['baseline_canonical_inodes'], $saved['baseline_operational_inodes']) !== $saved['baseline_inodes']) {
                    throw new \RuntimeException('Combined quota baseline is invalid; admission remains held.');
                }
            }
            if ($saved !== null && $saved['identity'] === $identity) {
                if ($saved['schema'] === 1) {
                    // Existing canonical-only observations cannot silently grandfather already present programs.
                    if ($operational['objects'] !== 0) { throw new \RuntimeException('Operational storage was added to an older quota observation; confirm a fresh quota observation before admission.'); }
                    $saved['schema'] = 2; $saved['operational_namespace_id'] = $operational['namespace_id'];
                    $saved['baseline_canonical_bytes'] = $saved['baseline_bytes']; $saved['baseline_canonical_inodes'] = $saved['baseline_inodes'];
                    $saved['baseline_operational_bytes'] = $saved['baseline_operational_inodes'] = 0;
                    Files::json($path, $saved);
                } elseif (($saved['operational_namespace_id'] ?? null) !== $operational['namespace_id']) {
                    throw new \RuntimeException('Operational storage roots changed under an existing quota observation; confirm a fresh quota observation before admission.');
                }
            }
            if ($saved === null || $saved['identity'] !== $identity) {
                $saved = ['schema' => 2, 'identity' => $identity, 'confirmed' => $confirmed,
                    'baseline_bytes' => $current['bytes'], 'baseline_inodes' => $current['objects'],
                    'baseline_canonical_bytes' => (int) $canonical['bytes'], 'baseline_canonical_inodes' => (int) $canonical['objects'],
                    'baseline_operational_bytes' => $operational['bytes'], 'baseline_operational_inodes' => $operational['objects'],
                    'operational_namespace_id' => $operational['namespace_id'], 'recorded_at' => time()];
                Files::json($path, $saved);
            }
            $growthBytes = max(0, $current['bytes'] - $saved['baseline_bytes']);
            $growthInodes = max(0, $current['objects'] - $saved['baseline_inodes']);
            return ['account_remaining_bytes' => $confirmed['account_remaining_bytes'] === null ? null : max(0, $confirmed['account_remaining_bytes'] - $growthBytes),
                'inodes_remaining' => $confirmed['inodes_remaining'] === null ? null : max(0, $confirmed['inodes_remaining'] - $growthInodes),
                'baseline_bytes' => $saved['baseline_bytes'], 'baseline_inodes' => $saved['baseline_inodes'],
                'private_growth_bytes' => $growthBytes, 'private_growth_inodes' => $growthInodes,
                'canonical_growth_bytes' => max(0, (int) $canonical['bytes'] - (int) $saved['baseline_canonical_bytes']),
                'operational_growth_bytes' => max(0, $operational['bytes'] - (int) $saved['baseline_operational_bytes']),
                'operational_usage' => $operational,
                'quota_observed_at' => $confirmed['quota_observed_at'], 'recorded_at' => $saved['recorded_at'],
                'warnings' => ['Confirmed remaining quotas are reduced by subsequent canonical and configured operational growth. Other account changes require a fresh observation.']];
        });
    }
}

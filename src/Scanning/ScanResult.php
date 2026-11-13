<?php
declare(strict_types=1);
namespace WPClamAV\Scanning;

/** A malformed response is never a no-detection response. */
final class ScanResult
{
    public static function validate(array $result, array $receipt, string $profile): array
    {
        foreach (['completed', 'applicability_proven', 'coverage_qualified'] as $key) {
            if (!array_key_exists($key, $result) || !is_bool($result[$key])) { throw new \RuntimeException('Scanner response lacks a boolean ' . $key . '.'); }
        }
        if (($result['protocol'] ?? null) !== 1 || !in_array($result['status'] ?? null, ['clean', 'detected', 'held', 'failed'], true)) {
            throw new \RuntimeException('Unsupported scanner result protocol.');
        }
        if (!is_string($result['sha256'] ?? null) || !hash_equals($receipt['sha256'], $result['sha256']) || ($result['size'] ?? null) !== $receipt['size']) {
            throw new \RuntimeException('Scanner result does not identify the preserved original.');
        }
        if (($result['requested_profile'] ?? null) !== $profile || ($result['effective_profile'] ?? null) !== $profile) {
            throw new \RuntimeException('Scanner changed the requested database profile.');
        }
        foreach (['engine_version', 'generation_id'] as $key) {
            if (!is_string($result[$key] ?? null) || $result[$key] === '' || strlen($result[$key]) > 256) { throw new \RuntimeException('Scanner result lacks bound engine/database identity.'); }
        }
        if (!is_array($result['findings'] ?? null) || !is_array($result['reason_codes'] ?? null)) { throw new \RuntimeException('Scanner findings/reasons must be arrays.'); }
        if ($result['status'] === 'clean' && (!$result['completed'] || !$result['applicability_proven'] || !$result['coverage_qualified'] || $result['findings'] !== [])) {
            throw new \RuntimeException('Scanner asserted clean without complete qualified coverage.');
        }
        if ($result['status'] === 'detected' && ($result['findings'] === [] || !$result['completed'])) {
            throw new \RuntimeException('Incomplete detections must remain held or failed.');
        }
        return $result;
    }
}

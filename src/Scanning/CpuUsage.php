<?php
declare(strict_types=1);
namespace WPClamAV\Scanning;

final class CpuUsage
{
    public static function seconds(int $who = 0): ?float
    {
        $usage = function_exists('getrusage') ? getrusage($who) : false;
        if (!is_array($usage)) { return null; }
        return (float) ($usage['ru_utime.tv_sec'] ?? 0) + (float) ($usage['ru_stime.tv_sec'] ?? 0)
            + ((float) ($usage['ru_utime.tv_usec'] ?? 0) + (float) ($usage['ru_stime.tv_usec'] ?? 0)) / 1000000;
    }

    public static function remaining(array $limits): float
    {
        $remaining = (float) ($limits['wall_seconds'] ?? 0);
        if (isset($limits['deadline_monotonic'])) {
            $remaining = min($remaining, (float) $limits['deadline_monotonic'] - hrtime(true) / 1e9);
        }
        if (!is_finite($remaining) || $remaining <= 0) { throw new \RuntimeException('The admitted scan deadline was exhausted.'); }
        return $remaining;
    }
}

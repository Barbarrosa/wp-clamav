<?php
declare(strict_types=1);
namespace WPClamAV\Scanning;

interface ScannerBackend
{
    /** @param resource $original */
    public function scan($original, array $receipt, string $profile, array $limits): array;
}

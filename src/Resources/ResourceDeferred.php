<?php
declare(strict_types=1);
namespace WPClamAV\Resources;
final class ResourceDeferred extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $retryAt)
    { parent::__construct('Scanner work deferred: ' . $reason); }
}

<?php
declare(strict_types=1);
namespace WPClamAV\Scanning;

/** Usage belongs to the reaped process, including failed and timed-out attempts. */
final class ProcessFailure extends \RuntimeException
{
    public function __construct(string $message, public readonly ?float $cpuSeconds = null,
        ?\Throwable $previous = null, public readonly ?string $deferredReason = null,
        public readonly ?int $retryAt = null)
    { parent::__construct($message, 0, $previous); }
}

<?php
declare(strict_types=1);
namespace WPClamAV\Storage;

final class StorageException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $receiptId = null)
    {
        parent::__construct($message);
    }
}

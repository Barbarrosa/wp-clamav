<?php
declare(strict_types=1);
namespace WPClamAV\Resources;
use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\Files;
final class DispatchLease
{
    private int $ownerPid;
    private function __construct(private mixed $stream) { $this->ownerPid = getmypid(); }
    public static function acquire(FileSubmissionStore $coordinator): self
    {
        $path = $coordinator->directory() . '/locks/native-dispatch';
        if (file_exists($path) || is_link($path)) { Files::regular($path); }
        $stream = Files::track($path, static function () use ($path) {
            $mask = umask(0077);
            try { return @fopen($path, 'c+b'); } finally { umask($mask); }
        });
        if ($stream === false) { throw new \RuntimeException('Cannot open the account scanner dispatch lock.'); }
        try {
            $stat = Files::regular($path); $opened = fstat($stream);
            if ($opened === false || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev']) { throw new \RuntimeException('Scanner dispatch lock changed while opening.'); }
            if (!flock($stream, LOCK_EX | LOCK_NB)) { throw new ResourceDeferred('dispatch_busy', time() + 5); }
            return new self($stream);
        } catch (\Throwable $error) { fclose($stream); throw $error; }
    }
    public function release(): void
    {
        // Closing our reference preserves the lock while an admitted native
        // descendant still owns its explicit inherited descriptor.
        if (is_resource($this->stream)) { fclose($this->stream); }
        $this->stream = null;
    }
    public function descriptor(): mixed
    {
        if (getmypid() !== $this->ownerPid || !is_resource($this->stream)) { throw new \LogicException('Only the admitting process may pass a live dispatch descriptor.'); }
        return $this->stream;
    }
    public function __destruct() { $this->release(); }
    private function __clone() {}
    public function __serialize(): array { throw new \LogicException('Dispatch leases cannot be serialized.'); }
}

<?php
declare(strict_types=1);
namespace WPClamAV\Scanning;

use WPClamAV\Contracts\SubmissionStore;
use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Storage\StorageException;

final class IntakeService
{
    private ?\Closure $notify;
    public function __construct(private SubmissionStore $store, private ?ScannerBackend $backend, private ResourcePolicy $policy,
        private string $defaultProfile = 'full', ?callable $notify = null)
    {
        $this->notify = $notify ? \Closure::fromCallable($notify) : null;
        if ($store instanceof \WPClamAV\Storage\FileSubmissionStore) { $this->policy->bindStore($store); }
    }

    public function receivePath(string $path, array $context = []): array
    {
        $before = @lstat($path);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000 || is_link($path)) { throw new StorageException('Upload input must be a regular non-symlink file.'); }
        $stream = @fopen($path, 'rb');
        if ($stream === false) { throw new StorageException('Could not read upload input.'); }
        try {
            $after = fstat($stream);
            if ($after === false || $before['ino'] !== $after['ino'] || $before['dev'] !== $after['dev']) { throw new StorageException('Upload changed while opening.'); }
            $context['size'] = $before['size'];
            return $this->receiveStream($stream, $context);
        } finally { fclose($stream); }
    }

    public function receiveStream($stream, array $context = []): array
    {
        $limit = $this->policy->fileLimit($context);
        if ($limit !== null) { $context['max_bytes'] = $limit; }
        return $this->store->ingestStream($stream, $context);
    }

    public function assess(string $id, ?string $profile = null): array
    {
        $profile ??= $this->defaultProfile;
        if (!in_array($profile, ['full', 'pdf-jpeg-png-pe-exclusions'], true)) { throw new \InvalidArgumentException('Unknown database profile.'); }
        $operation = function () use ($id, $profile): array {
            $receipt = $this->store->get($id);
            // Revoke earlier clean capabilities BEFORE retry or any fallible native work.
            $this->store->appendEvent($id, ['status' => 'held', 'completed' => false, 'applicability_proven' => false, 'coverage_qualified' => false,
                'requested_profile' => $profile, 'effective_profile' => $profile, 'findings' => [], 'reason_codes' => ['scan_in_progress']]);
            $stream = null;
            $lease = null; $actualCpu = null;
            try {
                if (!$receipt['complete'] || isset($receipt['deleted_at'])) { throw new \RuntimeException('Original is incomplete or deleted.'); }
                if ($this->backend === null) { throw new \RuntimeException('No compatible scanner backend is configured.'); }
                $limits = $this->backend instanceof RemoteBackend ? $this->policy->remoteLimits(PHP_SAPI !== 'cli') : $this->policy->nativeLimits(PHP_SAPI !== 'cli');
                if ($this->backend instanceof LocalBackend) { $lease = $this->policy->beginNative('scan:' . $id, $limits); }
                $stream = $this->store->open($id);
                $this->verify($stream, $receipt);
                $result = $this->backend->scan($stream, $receipt, $profile, $limits);
                $actualCpu = isset($result['local_cpu_seconds']) ? (float) $result['local_cpu_seconds'] : null;
                $this->verify($stream, $receipt);
                $result = ScanResult::validate($result, $receipt, $profile);
                $intakeHolds = $receipt['context']['intake_holds'] ?? [];
                if (($receipt['context']['adapter_qualified'] ?? true) !== true) { $intakeHolds[] = 'adapter_unqualified'; }
                if ($intakeHolds !== [] && $result['status'] === 'clean') {
                    $result['status'] = 'held';
                    $result['reason_codes'] = array_values(array_unique(array_merge($result['reason_codes'], $intakeHolds)));
                    $result['applicability_proven'] = false;
                }
            } catch (\Throwable $error) {
                $result = ['protocol' => 1, 'status' => 'failed', 'completed' => false, 'applicability_proven' => false, 'coverage_qualified' => false,
                    'requested_profile' => $profile, 'effective_profile' => $profile, 'engine_version' => 'unavailable', 'generation_id' => 'unavailable',
                    'findings' => [], 'reason_codes' => ['scan_failed'], 'error' => substr($error->getMessage(), 0, 400)];
            } finally {
                if (is_resource($stream)) { fclose($stream); }
                $this->policy->finishNative($lease, $actualCpu);
            }
            $event = $this->store->appendEvent($id, $result);
            if ($this->notify !== null) { ($this->notify)($event); }
            return $event;
        };
        return method_exists($this->store, 'withLock') ? $this->store->withLock('scan-' . $id, $operation, true) : $operation();
    }

    private function verify($stream, array $receipt): void
    {
        rewind($stream); $hash = hash_init('sha256'); $bytes = hash_update_stream($hash, $stream);
        if ($bytes !== $receipt['size'] || !hash_equals($receipt['sha256'], hash_final($hash))) { throw new \RuntimeException('Preserved original integrity failed.'); }
        rewind($stream);
    }

    public function get(string $id): array { return $this->store->get($id); }

    public function canPublish(string $id): bool
    {
        try {
            $receipt = $this->store->get($id);
            if (!$receipt['complete'] || isset($receipt['deleted_at'])) { return false; }
            $stream = $this->store->open($id);
            try { $this->verify($stream, $receipt); } finally { fclose($stream); }
            $approval = $receipt['manual_approval'] ?? null;
            if (is_array($approval) && hash_equals($receipt['sha256'], $approval['sha256'] ?? '') && ($approval['event_id'] ?? null) === $receipt['latest_event_id']) { return true; }
            $event = $this->store->latestEvent($id);
            return $event !== null && $event['status'] === 'clean' && ($event['completed'] ?? false) === true && ($event['applicability_proven'] ?? false) === true
                && ($event['coverage_qualified'] ?? false) === true && hash_equals($receipt['sha256'], $event['sha256']);
        } catch (\Throwable) { return false; }
    }

    public function authorizeManual(string $id, int $actor, string $reason): array
    {
        if (!method_exists($this->store, 'approve')) { throw new \RuntimeException('The storage provider does not support audited approval.'); }
        return $this->store->approve($id, $actor, $reason);
    }

    public function delete(string $id, int $actor, string $reason): void { $this->store->delete($id, $actor, $reason); }
}

<?php
declare(strict_types=1);
namespace WPClamAV\Scanning;

use WPClamAV\Contracts\SubmissionStore;
use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Resources\ResourceDeferred;
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

    /** Policy-ineligible public files never pay for an engine initialization. */
    public function automaticEligibility(array $receipt, bool $explicit = false, ?string $submissionId = null): array
    {
        if (!$receipt['complete'] || isset($receipt['deleted_at'])) { return ['original_incomplete_or_deleted']; }
        if (($receipt['size'] ?? 0) < 1) { return ['empty-original']; }
        if ($explicit) { return []; }
        $context = $receipt['context'] ?? [];
        $holds = $context['intake_holds'] ?? [];
        if (!is_array($holds)) { $holds = ['intake_policy_invalid']; }
        if (($context['adapter_qualified'] ?? true) !== true) { $holds[] = 'adapter_unqualified'; }
        if (($context['scan_requires_submission'] ?? false) === true) {
            try {
                if ($submissionId === null) { throw new \RuntimeException('No submitted narrative is bound.'); }
                $submission = $this->store->submission($submissionId);
                $fields = $submission['fields'];
                if (!in_array($receipt['id'], $submission['receipt_ids'], true)
                    || !in_array($receipt['id'], $fields['scan_receipt_ids'] ?? [], true)
                    || ($fields['origin'] ?? null) !== ($context['origin'] ?? null)
                    || ($fields['site_id'] ?? null) !== ($context['site_id'] ?? null)
                    || ($fields['form_id'] ?? null) !== ($context['form_id'] ?? null)) {
                    throw new \RuntimeException('The submission does not authorize this attachment.');
                }
            } catch (\Throwable) { $holds[] = 'awaiting_submission'; }
        }
        return array_values(array_unique(array_filter($holds, 'is_string')));
    }

    public function assess(string $id, ?string $profile = null, bool $explicit = false, ?string $submissionId = null, bool $queuedAutomatic = false): array
    {
        $profile ??= $this->defaultProfile;
        if (!in_array($profile, ['full', 'pdf-jpeg-png-pe-exclusions'], true)) { throw new \InvalidArgumentException('Unknown database profile.'); }
        $operation = function () use ($id, $profile, $explicit, $submissionId, $queuedAutomatic): array {
            $receipt = $this->store->get($id);
            $latest = $this->store->latestEvent($id);
            // A synchronous entry point may have completed this original after
            // the queue inspected it. Recheck while holding the receipt scan
            // lock, before revocation, hashes, admission, or native startup.
            if ($queuedAutomatic && !$explicit && $latest !== null
                && !array_intersect($latest['reason_codes'] ?? [], ['scan_in_progress', 'resource_deferred', 'awaiting_submission'])) {
                return $latest + ['_dispatch_skipped' => true];
            }
            if (!$explicit && empty($receipt['manual_approval']) && $latest !== null && in_array('resource_deferred', $latest['reason_codes'] ?? [], true)
                && ($latest['retry_at'] ?? 0) > $this->policy->now()) { return $latest; }
            $ineligible = $this->automaticEligibility($receipt, $explicit, $submissionId);
            if ($ineligible !== []) {
                $reasons = array_merge(['input_policy_held'], $ineligible);
                if (empty($receipt['manual_approval']) && $latest !== null && ($latest['reason_codes'] ?? []) === $reasons && ($latest['requested_profile'] ?? null) === $profile) { return $latest; }
                $event = $this->store->appendEvent($id, $this->held($profile, $reasons));
                if ($this->notify !== null) { ($this->notify)($event); }
                return $event;
            }
            // Revoke earlier clean capabilities BEFORE retry or any fallible native work.
            $this->store->appendEvent($id, ['status' => 'held', 'completed' => false, 'applicability_proven' => false, 'coverage_qualified' => false,
                'requested_profile' => $profile, 'effective_profile' => $profile, 'findings' => [], 'reason_codes' => ['scan_in_progress']]);
            $stream = null;
            $lease = null; $actualCpu = null; $dispatch = null; $selfBefore = null;
            $localUsage = !($this->backend instanceof RemoteBackend);
            try {
                if (!$receipt['complete'] || isset($receipt['deleted_at'])) { throw new \RuntimeException('Original is incomplete or deleted.'); }
                if ($this->backend === null) { throw new ResourceDeferred('backend_unavailable', $this->policy->now() + 300); }
                // One shared account slot is held before hashes, copies or processes.
                $dispatch = $this->policy->acquireDispatch();
                $limits = $this->backend instanceof RemoteBackend ? $this->policy->remoteLimits(PHP_SAPI !== 'cli') : $this->policy->nativeLimits(PHP_SAPI !== 'cli');
                $limits['deadline_monotonic'] = hrtime(true) / 1e9 + $limits['wall_seconds'];
                $limits['now_epoch'] = $this->policy->now();
                $limits['dispatch_stream'] = $dispatch->descriptor();
                $lease = $this->policy->beginScan('scan:' . $id, $limits);
                $selfBefore = CpuUsage::seconds();
                $actualCpu = $localUsage ? 0.0 : null;
                $stream = $this->store->open($id);
                $this->verify($stream, $receipt, $limits);
                $actualCpu = null;
                $result = $this->backend->scan($stream, $receipt, $profile, $limits);
                $actualCpu = $localUsage && isset($result['local_cpu_seconds']) ? (float) $result['local_cpu_seconds'] : null;
                $this->verify($stream, $receipt, $limits);
                $result = ScanResult::validate($result, $receipt, $profile);
                $intakeHolds = $receipt['context']['intake_holds'] ?? [];
                if (($receipt['context']['adapter_qualified'] ?? true) !== true) { $intakeHolds[] = 'adapter_unqualified'; }
                if ($intakeHolds !== [] && $result['status'] === 'clean') {
                    $result['status'] = 'held';
                    $result['reason_codes'] = array_values(array_unique(array_merge($result['reason_codes'], $intakeHolds)));
                    $result['applicability_proven'] = false;
                }
            } catch (ResourceDeferred $error) {
                $result = $this->held($profile, ['resource_deferred', $error->reason]);
                $result['retry_at'] = $error->retryAt;
            } catch (\Throwable $error) {
                if ($error instanceof ProcessFailure) { $actualCpu = $error->cpuSeconds; }
                if ($error instanceof ProcessFailure && $error->deferredReason !== null) {
                    $result = $this->held($profile, ['resource_deferred', $error->deferredReason]);
                    $result['retry_at'] = $error->retryAt ?? $this->policy->now() + 60;
                } else {
                $result = ['protocol' => 1, 'status' => 'failed', 'completed' => false, 'applicability_proven' => false, 'coverage_qualified' => false,
                    'requested_profile' => $profile, 'effective_profile' => $profile, 'engine_version' => 'unavailable', 'generation_id' => 'unavailable',
                    'findings' => [], 'reason_codes' => ['scan_failed'], 'error' => substr($error->getMessage(), 0, 400)];
                }
            } finally {
                if (is_resource($stream)) { fclose($stream); }
                try {
                    $after = CpuUsage::seconds();
                    $total = $localUsage && $actualCpu !== null && $selfBefore !== null && $after !== null
                        ? $actualCpu + max(0, $after - $selfBefore) : null;
                    $this->policy->finishNative($lease, $total);
                } finally { $dispatch?->release(); }
            }
            $event = $this->store->appendEvent($id, $result);
            if ($this->notify !== null && !in_array('resource_deferred', $result['reason_codes'], true)) { ($this->notify)($event); }
            return $event;
        };
        return method_exists($this->store, 'withLock') ? $this->store->withLock('scan-' . $id, $operation, true) : $operation();
    }

    private function held(string $profile, array $reasons): array
    {
        return ['protocol' => 1, 'status' => 'held', 'completed' => false, 'applicability_proven' => false,
            'coverage_qualified' => false, 'requested_profile' => $profile, 'effective_profile' => $profile,
            'engine_version' => 'unavailable', 'generation_id' => 'unavailable', 'findings' => [], 'reason_codes' => $reasons];
    }

    private function verify($stream, array $receipt, ?array $limits = null): void
    {
        rewind($stream); $hash = hash_init('sha256'); $bytes = 0;
        while (!feof($stream)) {
            if ($limits !== null) { CpuUsage::remaining($limits); }
            $chunk = fread($stream, 65536);
            if ($chunk === false || ($chunk === '' && !feof($stream))) { throw new \RuntimeException('Cannot read preserved original.'); }
            $bytes += strlen($chunk);
            if ($bytes > $receipt['size']) { throw new \RuntimeException('Preserved original grew beyond its identity.'); }
            hash_update($hash, $chunk);
        }
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

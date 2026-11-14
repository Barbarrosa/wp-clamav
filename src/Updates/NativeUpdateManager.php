<?php
declare(strict_types=1);
namespace WPClamAV\Updates;

use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Resources\ResourceDeferred;
use WPClamAV\Scanning\ProcessRunner;
use WPClamAV\Storage\Files;

/** Official signature updates run natively; no PHP download of ClamAV database content. */
final class NativeUpdateManager
{
    public function __construct(private array $settings, private ResourcePolicy $policy, private ?ProcessRunner $runner = null)
    { $this->runner ??= new ProcessRunner(); }

    public function run(?array $candidate = null): array
    {
        $dispatch = null; $intent = null;
        try {
            return $this->runAdmitted($candidate, $dispatch, $intent);
        } catch (ResourceDeferred $error) {
            return ['protocol' => 1, 'status' => 'deferred', 'reason' => $error->reason, 'next_attempt_after' => $error->retryAt];
        } finally {
            if ($dispatch !== null) {
                try { $this->policy->finishMaintenanceIntent($intent); }
                finally { $dispatch->release(); }
            }
        }
    }

    private function runAdmitted(?array $candidate, ?\WPClamAV\Resources\DispatchLease &$dispatch, ?string &$intent): array
    {
        $scanner = (string) ($this->settings['local_binary'] ?? '');
        $updater = (string) ($this->settings['updater_binary'] ?? ($scanner !== '' ? dirname($scanner) . '/compact-update' : ''));
        if ($candidate !== null) {
            if (!preg_match('/\Apkg-[a-f0-9]{64}\z/D', $candidate['id'] ?? '') || !is_string($candidate['path'] ?? null) || !str_starts_with($candidate['path'], '/')) {
                throw new \RuntimeException('Invalid authenticated candidate package.');
            }
            $scanner = $candidate['path'] . '/bin/compact-scan';
            $updater = $candidate['path'] . '/bin/compact-update';
        }
        $state = (string) ($this->settings['state_dir'] ?? $this->settings['generation_dir'] ?? '');
        $certs = (string) ($this->settings['certificates_dir'] ?? '');
        if ($candidate !== null && ($certs === '' || ($this->settings['certificates_explicit'] ?? false) !== true)) { $certs = $candidate['path'] . '/certs'; }
        if ($state === '' || !is_dir($state) || $certs === '' || !is_dir($certs) || !is_executable($scanner) || !is_executable($updater)) {
            return ['status' => 'not-configured', 'reason' => 'Configure verified native executables, certificates and private state before enabling direct updates.'];
        }
        $expectedCurrent = $this->settings['expected_current_sha256'] ?? '';
        if (!is_string($expectedCurrent) || ($expectedCurrent !== 'absent' && !preg_match('/\A[a-f0-9]{64}\z/D', $expectedCurrent))) {
            return ['status' => 'not-configured', 'reason' => 'Resolve the native program and current-pointer fingerprint together before updating.'];
        }
        Files::privateDirectory($state);
        $budgets = $this->settings['budgets'] ?? [];
        $schedule = $state . '/wordpress-update-schedule.json';
        $now = time();
        $previous = file_exists($schedule) ? Files::readJson($schedule) : [];
        // FreshClam defaults to twelve checks/day. Provider scheduling guidance and
        // persisted CDN cooldowns can only lengthen, never shorten, the interval.
        $interval = max(7200, (int) ($budgets['provider_cron_interval_seconds'] ?? 1800), (int) ($budgets['update_interval_seconds'] ?? 0));
        if ($candidate === null && $now < (int) ($previous['next_at'] ?? 0)) { return ['status' => 'deferred', 'next_at' => $previous['next_at']]; }
        $intent = $this->policy->signalMaintenance();
        $dispatch = $this->policy->acquireDispatch('maintenance');
        $limits = $this->policy->nativeLimits(PHP_SAPI !== 'cli');
        $limits['dispatch_stream'] = $dispatch->descriptor();
        $storage = $this->policy->updateStorageLimits($state);
        $limits['file_bytes'] = min($limits['file_bytes'], intdiv($storage['max_state_bytes'], 5));
        if ($limits['file_bytes'] < 1) { throw new \RuntimeException('No bounded native update-file allocation remains.'); }
        if (isset($budgets['update_wall_seconds'])) { $limits['wall_seconds'] = min($limits['wall_seconds'], (float) $budgets['update_wall_seconds']); }
        foreach (['update_line_bytes', 'update_reserve_bytes', 'freshclam_connect_seconds', 'freshclam_low_speed_seconds', 'freshclam_max_attempts'] as $required) {
            if (!isset($budgets[$required]) || !is_numeric($budgets[$required]) || $budgets[$required] <= 0) {
                return ['status' => 'not-configured', 'reason' => 'Confirm updater budget: ' . $required];
            }
        }
        // Persist attempt spacing before network work, including failed requests.
        Files::json($schedule, ['attempted_at' => $now, 'next_at' => $now + $interval]);
        $doctor = $this->supervise(array_merge([$scanner, 'doctor', '--state', $state], ProcessRunner::limitArguments($limits)), $limits, $state, 'update:preflight');
        if ($doctor['exit_code'] !== 0) { throw new \RuntimeException('Native update preflight failed.'); }
        $identity = json_decode(trim($doctor['stdout']), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($identity) || !preg_match('/\A[a-f0-9]{64}\z/D', $identity['engine_sha256'] ?? '') || !preg_match('/\A[a-f0-9]{64}\z/D', $identity['scan_options_sha256'] ?? '')) {
            throw new \RuntimeException('Native component did not identify its exact runtime/options.');
        }
        // Preflight is charged before a second reservation. Recalculate the
        // request deadline and rolling allowances rather than reusing them.
        $limits = $this->policy->nativeLimits(PHP_SAPI !== 'cli');
        $limits['dispatch_stream'] = $dispatch->descriptor();
        $storage = $this->policy->updateStorageLimits($state);
        $limits['file_bytes'] = min($limits['file_bytes'], intdiv($storage['max_state_bytes'], 5));
        if ($limits['file_bytes'] < 1) { throw new \RuntimeException('No bounded native update-file allocation remains.'); }
        if (isset($budgets['update_wall_seconds'])) { $limits['wall_seconds'] = min($limits['wall_seconds'], (float) $budgets['update_wall_seconds']); }
        $args = array_merge([$updater, 'update', '--state', $state, '--scanner', $scanner, '--certs', $certs,
            '--expected-current-sha256', $expectedCurrent], ProcessRunner::limitArguments($limits), [
            '--max-line-bytes', (string) (int) $budgets['update_line_bytes'], '--connect-seconds', (string) (int) $budgets['freshclam_connect_seconds'],
            '--low-speed-seconds', (string) (int) $budgets['freshclam_low_speed_seconds'], '--max-attempts', (string) (int) $budgets['freshclam_max_attempts'],
            '--max-age-seconds', (string) (int) ($budgets['database_max_age_seconds'] ?? 172800), '--disk-reserve-bytes', (string) $storage['disk_reserve_bytes'],
            '--max-state-bytes', (string) $storage['max_state_bytes'], '--max-state-inodes', (string) $storage['max_state_inodes'],
            '--engine-sha256', $identity['engine_sha256'], '--options-sha256', $identity['scan_options_sha256']]);
        if ($candidate !== null) { $args = array_merge($args, ['--program-id', $candidate['id']]); }
        $result = $this->supervise($args, $limits, $state, 'update:official');
        $record = null;
        foreach (preg_split('/\R/', trim($result['stdout'])) as $line) {
            if ($line === '' || $line[0] !== '{') { continue; }
            $candidate = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            if (is_array($candidate) && ($candidate['protocol'] ?? null) === 1) { $record = $candidate; }
        }
        if ($record === null || !in_array($result['exit_code'], [0, 3], true)) { throw new \RuntimeException('Official update failed; previous generation remains active.'); }
        return $record;
    }

    private function supervise(array $arguments, array $limits, string $state, string $job): array
    {
        $token = $this->policy->beginNative($job, $limits);
        $cpu = null;
        try {
            $result = $this->runner->run($arguments, $limits, $state);
            $cpu = $result['cpu_seconds'];
            return $result;
        } finally { $this->policy->finishNative($token, $cpu); }
    }
}

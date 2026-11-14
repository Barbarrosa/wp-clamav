<?php
declare(strict_types=1);
namespace WPClamAV;

use WPClamAV\Contracts\SubmissionStore;
use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\WordPressIndex;
use WPClamAV\Storage\Files;
use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Resources\NetworkAllocations;
use WPClamAV\Scanning\IntakeService;
use WPClamAV\Scanning\LocalBackend;
use WPClamAV\Scanning\RemoteBackend;
use WPClamAV\Notifications\NotificationManager;
use WPClamAV\Notifications\WpdbNotificationRepository;
use WPClamAV\Notifications\WordPressNotificationController;
use WPClamAV\Updates\NativeUpdateManager;

final class Application
{
    public readonly SubmissionStore $store;
    public readonly IntakeService $intake;
    public readonly ResourcePolicy $policy;
    public readonly ?NotificationManager $notifications;
    public readonly ?WordPressNotificationController $notificationController;
    private array $warnings = [];
    private ?array $networkStorage = null;

    private function __construct(private array $settings)
    {
        $multisite = function_exists('is_multisite') && is_multisite();
        $roots = array_filter([defined('ABSPATH') ? ABSPATH : null, $_SERVER['DOCUMENT_ROOT'] ?? null]);
        $index = null; $repository = null;
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) {
            $index = new WordPressIndex($wpdb);
            $repository = new WpdbNotificationRepository($wpdb);
            if ((int) get_option('wp_clamav_schema', 0) < 1) {
                $index->install(); $repository->install();
                update_option('wp_clamav_schema', 1, false);
            }
        }
        $path = (string) ($settings['storage_path'] ?? '');
        $base = rtrim($path, '/');
        $coordinator = null;
        if ($multisite) {
            // One canonical namespace per blog; network configuration is not cross-site access.
            $parent = realpath(dirname($base));
            if ($base === '' || $base[0] !== '/' || $parent === false || is_link($base)) { throw new \RuntimeException('Configure a private network storage root.'); }
            $base = $parent . '/' . basename($base);
            foreach ($roots as $root) {
                $root = realpath($root);
                if ($root !== false && ($base === $root || str_starts_with($base . '/', rtrim($root, '/') . '/'))) {
                    throw new \RuntimeException('The network storage root must be outside every document root.');
                }
            }
            if (!file_exists($base) && !mkdir($base, 0700)) { throw new \RuntimeException('Cannot create the private network storage root.'); }
            Files::privateDirectory($base);
            $coordinator = new FileSubmissionStore($base . '/account-control', $roots);
            $allocation = NetworkAllocations::allocate($coordinator, $settings['budgets'] ?? [], max(1, (int) get_sites(['count' => true, 'number' => 0])));
            $settings['budgets'] = $allocation['budgets'];
            $this->networkStorage = $allocation['diagnostics'];
            $this->networkStorage['configured_allocation_bytes'] = $this->networkStorage['configured_network_storage_bytes'];
            $this->networkStorage['per_site_allocation_bytes'] = $this->networkStorage['per_site']['storage_bytes'];
            $path = $base;
            $path = rtrim($path, '/') . '/site-' . get_current_blog_id();
        }
        $this->settings = $settings;
        $policy = new ResourcePolicy($settings);
        // Trusted server integrations may supply a policy, including fixture clocks.
        if (function_exists('apply_filters')) { $policy = apply_filters('wp_clamav_resource_policy', $policy, $settings); }
        if (!$policy instanceof ResourcePolicy) { throw new \RuntimeException('The resource-policy integration returned an invalid policy.'); }
        $this->policy = $policy;
        $policy = $this->policy;
        $this->store = new FileSubmissionStore($path, $roots,
            static fn(?int $length, array $usage, array $context) => $policy->admitStorage($path, $length, $usage, $context), $index);
        $this->policy->bindStore($this->store, $coordinator);
        $this->settings['programs_dir'] = (string) ($settings['programs_dir'] ?? '') ?: $base . '/programs';
        $state = (string) (($settings['state_dir'] ?? '') ?: ($settings['generation_dir'] ?? ''));
        if ($state === '') { $state = $base . '/native-state'; }
        $settings['state_dir'] = $state; $this->settings['state_dir'] = $state;
        $operationalPaths = [$this->store->scratchDirectory()];
        if ($coordinator !== null) { $operationalPaths[] = $coordinator->directory(); }
        if (($settings['backend'] ?? 'local') === 'local') {
            $operationalPaths[] = $state;
            $operationalPaths[] = $this->settings['programs_dir'];
        }
        $this->policy->setOperationalPaths($operationalPaths);
        $current = [];
        $this->settings['expected_current_sha256'] = 'absent';
        $backendCertificates = !empty($settings['certificates_dir']) ? (string) $settings['certificates_dir'] : null;
        $this->settings['certificates_explicit'] = $backendCertificates !== null;
        if (($settings['backend'] ?? 'local') === 'local') {
            if (!file_exists($state)) { Files::track($state, static fn(): bool => mkdir($state, 0700)); }
            Files::privateDirectory($state);
            $settings['state_dir'] = $state; $this->settings['state_dir'] = $state;
            if (file_exists($state . '/current.json')) {
                Files::regular($state . '/current.json');
                $rawCurrent = file_get_contents($state . '/current.json', false, null, 0, 1048577);
                if (!is_string($rawCurrent) || strlen($rawCurrent) > 1048576) { throw new \RuntimeException('Invalid native current pointer.'); }
                $current = json_decode($rawCurrent, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($current)) { throw new \RuntimeException('Invalid native current pointer.'); }
                $this->settings['expected_current_sha256'] = hash('sha256', $rawCurrent);
            }
            if (isset($current['program'])) {
                if (!preg_match('/\Apkg-[a-f0-9]{64}\z/D', $current['program'])) { throw new \RuntimeException('Invalid active native program identity.'); }
                $base = $this->settings['programs_dir'] . '/releases/' . $current['program'];
                if (is_link($base) || !is_dir($base)) { throw new \RuntimeException('Selected native program directory is unavailable.'); }
                $settings['local_binary'] = $base . '/bin/compact-scan';
                $settings['updater_binary'] = $base . '/bin/compact-update';
                $this->settings['local_binary'] = $settings['local_binary'];
                $this->settings['updater_binary'] = $settings['updater_binary'];
                if ($backendCertificates === null) { $backendCertificates = $base . '/certs'; }
            }
            if ($backendCertificates !== null) { $this->settings['certificates_dir'] = $backendCertificates; }
        }
        $backend = null;
        try {
            $backend = ($settings['backend'] ?? 'local') === 'remote'
                ? new RemoteBackend((string) ($settings['endpoint'] ?? ''), (string) ($settings['remote_token'] ?? ''))
                : new LocalBackend((string) ($settings['local_binary'] ?? ''), $state, $this->store->scratchDirectory(), null,
                    $backendCertificates, (int) ($settings['budgets']['database_max_age_seconds'] ?? 172800), $current);
        } catch (\Throwable $error) { $this->warnings[] = $error->getMessage(); }
        if (function_exists('apply_filters')) { $backend = apply_filters('wp_clamav_backend', $backend, $settings); }
        if ($repository !== null) {
            $notificationSettings = $settings;
            $notificationSettings['admin_email'] = (string) get_option('admin_email');
            $notificationSettings['download_url'] = admin_url('admin-post.php?action=wp_clamav_download', 'https');
            $store = $this->store;
            $this->notifications = new NotificationManager($store, $repository, $notificationSettings, [self::class, 'sendMail'],
                static function (string $kind, string $recipient, int $bytes, int $encoded) use ($policy, $store): bool {
                    return $kind === 'attachment' ? $policy->attachmentAllowed($bytes, $encoded)
                        : $policy->messageAllowed($bytes, $encoded) && $policy->consumeMail($store, $recipient);
                }, $this->store->scratchDirectory());
            $this->notificationController = new WordPressNotificationController($this->notifications);
        } else { $this->notifications = null; $this->notificationController = null; }
        $this->intake = new IntakeService($this->store, $backend, $this->policy, (string) ($settings['profile'] ?? 'full'),
            $this->notificationController === null ? null : fn(array $event) => $this->notifyEvent($event));
    }

    public static function fromSettings(array $settings): self { return new self($settings); }

    public static function sendMail(array $message): bool
    {
        if (!function_exists('wp_mail') || !filter_var($message['to'] ?? '', FILTER_VALIDATE_EMAIL)) { return false; }
        $eligible = ($message['asset_eligible'] ?? false) === true;
        // A scoped final boundary also removes attachments/embeds added by unrelated mail filters.
        $guard = static function ($mailer) use ($message, $eligible): void {
            $mailer->clearAttachments();
            $mailer->clearAllRecipients();
            $mailer->clearReplyTos();
            $mailer->clearCustomHeaders();
            $mailer->addAddress($message['to']);
            $mailer->Subject = $message['subject'];
            $mailer->isHTML(false);
            $mailer->Body = $message['body'];
            $mailer->AltBody = '';
            if ($eligible) {
                foreach ($message['attachments'] as $attachment) {
                    $mailer->addAttachment($attachment['path'], $attachment['name']);
                }
            }
        };
        add_action('phpmailer_init', $guard, PHP_INT_MAX);
        try {
            return wp_mail($message['to'], $message['subject'], $message['body'], $message['headers'] ?? ['Content-Type: text/plain; charset=UTF-8'], []);
        } finally { remove_action('phpmailer_init', $guard, PHP_INT_MAX); }
    }

    public function doctor(): array
    {
        $diagnostics = $this->policy->diagnostics();
        $diagnostics['warnings'] = array_merge($diagnostics['warnings'], $this->warnings);
        $diagnostics['storage'] = $this->store->usage();
        if ($this->networkStorage !== null) { $diagnostics['network_storage'] = $this->networkStorage; }
        try { $diagnostics['storage']['headroom'] = $this->policy->storageHeadroom($this->store->directory(), $diagnostics['storage']); }
        catch (\Throwable $error) { $diagnostics['warnings'][] = $error->getMessage(); }
        $diagnostics['capabilities'] = ['proc_open' => function_exists('proc_open'), 'curl' => function_exists('curl_init'),
            'sodium' => function_exists('sodium_crypto_sign_verify_detached'), 'fsync' => function_exists('fsync')];
        $diagnostics['retention'] = 'Indefinite, including abandoned receipts. Explicit audited deletion only. Backups and email copies have independent retention.';
        $diagnostics['production_qualified'] = false;
        $diagnostics['siteground_qualified'] = false;
        return $diagnostics;
    }

    public function queueScan(string $id, ?string $profile = null, bool $explicit = false, ?int $actor = null, ?string $submissionId = null): array
    {
        $profile ??= (string) ($this->settings['profile'] ?? 'full');
        if (!in_array($profile, ['full', 'pdf-jpeg-png-pe-exclusions'], true) || $explicit && ($actor === null || $actor < 1)) {
            throw new \InvalidArgumentException('A supported profile and authorized actor are required.');
        }
        $receipt = $this->store->get($id);
        $latest = $this->store->latestEvent($id);
        $reasons = $latest['reason_codes'] ?? [];
        // Replayed browser tokens, submissions and old cron events cannot turn
        // a terminal receipt into another expensive scan or reset its retry state.
        if (!$explicit && $this->automaticQueueTerminal($latest)) {
            return ['status' => 'terminal', 'next_at' => null];
        }
        $ineligible = $this->intake->automaticEligibility($receipt, $explicit, $submissionId);
        if ($ineligible !== []) {
            $this->recordQueueHold($id, $profile, array_values(array_unique(array_merge(['input_policy_held'], $ineligible))));
            return ['status' => 'ineligible', 'reasons' => $ineligible, 'next_at' => null];
        }
        $queued = (new \WPClamAV\Scanning\DurableScanQueue($this->store))->enqueue($id, $profile, $explicit, $actor, $submissionId,
            (int) $this->policy->now(), !$explicit && in_array('resource_deferred', $reasons, true) ? (int) ($latest['retry_at'] ?? 0) : null);
        if ($queued['status'] === 'capacity') { $this->recordQueueHold($id, $profile, ['queue_capacity']); }
        return $queued;
    }

    private function recordQueueHold(string $id, string $profile, array $reasons): void
    {
        $latest = $this->store->latestEvent($id);
        if ($latest !== null && ($latest['reason_codes'] ?? []) === $reasons && ($latest['requested_profile'] ?? null) === $profile) { return; }
        $this->store->appendEvent($id, ['protocol' => 1, 'status' => 'held', 'completed' => false,
            'applicability_proven' => false, 'coverage_qualified' => false, 'requested_profile' => $profile,
            'effective_profile' => $profile, 'engine_version' => 'unavailable', 'generation_id' => 'unavailable',
            'findings' => [], 'reason_codes' => $reasons]);
    }

    private function automaticQueueTerminal(?array $latest): bool
    {
        return $latest !== null && !array_intersect($latest['reason_codes'] ?? [], ['scan_in_progress', 'resource_deferred', 'awaiting_submission']);
    }

    /** Repair missed legacy intents with an indexed keyset, never an unbounded directory offset walk. */
    private function recoverScanRequests(int $limit): int
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) { return 0; }
        $root = $this->store->directory();
        if (file_exists($root . '/.index-dirty.json') || file_exists($root . '/.usage-dirty.json')) { return 0; }
        if (file_exists($root . '/usage.json') && (Files::readJson($root . '/usage.json')['index_repair_required'] ?? false)) { return 0; }
        $path = $root . '/worker-cursor.json';
        $cursor = file_exists($path) ? Files::readJson($path) : [];
        $at = max(0, (int) ($cursor['created_at'] ?? 0)); $after = (string) ($cursor['id'] ?? '');
        if ($after !== '') { Files::id($after); }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,created_at FROM ' . $wpdb->prefix . 'clamav_receipts WHERE deleted_at IS NULL AND (created_at>%d OR (created_at=%d AND id>%s)) ORDER BY created_at ASC,id ASC LIMIT %d', $at, $at, $after, $limit), ARRAY_A);
        if (!is_array($rows)) { return 0; }
        $recovered = 0;
        foreach ($rows as $row) {
            $cursor = ['created_at' => (int) $row['created_at'], 'id' => Files::id((string) $row['id'])];
            try {
                if ($this->store instanceof FileSubmissionStore && !file_exists($root . '/receipts/' . $cursor['id'] . '.json')) { continue; }
                $receipt = $this->store->get($cursor['id']);
                $latest = $this->store->latestEvent($cursor['id']);
                if ($receipt['complete'] && !isset($receipt['deleted_at']) && ($latest === null || array_intersect(['scan_in_progress', 'resource_deferred'], $latest['reason_codes'] ?? []))) {
                    // New Forminator receipts need the trusted submission descriptor
                    // supplied by commitSubmission; recovery cannot invent one.
                    $queued = $this->queueScan($receipt['id']);
                    if ($queued['status'] === 'queued') { ++$recovered; }
                }
            } catch (\Throwable) { /* Canonical records remain visible for explicit review. */ }
        }
        Files::json($path, count($rows) < $limit ? ['created_at' => 0, 'id' => ''] : $cursor);
        return $recovered;
    }

    public function runQueue(): array
    {
        try {
            return $this->store->withLock('scan-dispatch', function (): array {
                $budgets = $this->settings['budgets'] ?? [];
                $limit = max(1, min(20, (int) ($budgets['worker_batch_files'] ?? 1)));
                $attempts = max(1, min(20, (int) ($budgets['worker_max_attempts'] ?? 3)));
                $seconds = min((float) ($budgets['worker_batch_wall_seconds'] ?? $budgets['worker_wall_seconds'] ?? 0),
                    (float) ($budgets['worker_wall_seconds'] ?? 0)) - (float) ($budgets['cleanup_seconds'] ?? 0);
                $now = (int) $this->policy->now();
                if ($seconds <= 0) { return ['scanned' => 0, 'held' => 1, 'next_at' => $now + 1800, 'reason' => 'worker_deadline_unconfigured']; }
                $deadline = hrtime(true) / 1e9 + $seconds;
                $queue = new \WPClamAV\Scanning\DurableScanQueue($this->store);
                $recovered = $this->recoverScanRequests($limit);
                $processed = 0; $held = 0; $skipped = 0; $nextAt = null;
                for ($jobs = 0; $jobs < $limit && hrtime(true) / 1e9 < $deadline; ++$jobs) {
                    $now = (int) $this->policy->now();
                    $claim = $queue->claim($now, $limit * 2, $attempts); $nextAt = $claim['next_at'];
                    if ($claim['request'] === null) { break; }
                    $request = $claim['request'];
                    if (!$request['explicit'] && $this->automaticQueueTerminal($this->store->latestEvent($request['id']))) {
                        $queue->finish($request); ++$skipped; continue;
                    }
                    if ($claim['exhausted']) {
                        $this->recordQueueHold($request['id'], $request['profile'], ['automatic_attempts_exhausted']);
                        $queue->finish($request); ++$held; continue;
                    }
                    try {
                        $event = $this->policy->withDeadline($deadline, fn(): array => $this->intake->assess($request['id'], $request['profile'], $request['explicit'], $request['submission_id'], true));
                        if (($event['_dispatch_skipped'] ?? false) === true) {
                            $queue->finish($request); ++$skipped; continue;
                        }
                        if (in_array('resource_deferred', $event['reason_codes'] ?? [], true)) {
                            $nextAt = max($now + 1, (int) ($event['retry_at'] ?? $now + 60));
                            $queue->finish($request, $nextAt, true); ++$held; break;
                        }
                        $queue->finish($request);
                        if (in_array('input_policy_held', $event['reason_codes'] ?? [], true)) { ++$held; }
                        else { ++$processed; }
                    } catch (\WPClamAV\Resources\ResourceDeferred $error) {
                        $nextAt = max($now + 1, (int) $error->retryAt);
                        $queue->finish($request, $nextAt, true); ++$held; break;
                    } catch (\Throwable) {
                        $nextAt = $now + min(1800, 60 * (2 ** min(5, $request['attempts'] - 1)));
                        $queue->finish($request, $nextAt); ++$held; break;
                    }
                }
                // Refresh the bounded index without claiming another native job.
                $remaining = $queue->status((int) $this->policy->now());
                if ($remaining['pending'] === 0) { $nextAt = null; }
                else { $nextAt = max((int) $this->policy->now() + 1, $nextAt ?? $remaining['next_at']); }
                $replayed = hrtime(true) / 1e9 < $deadline ? $this->reconcileNotifications($limit) : 0;
                $mail = hrtime(true) / 1e9 < $deadline ? ($this->notifications?->runQueue() ?? []) : [];
                return ['scanned' => $processed, 'held' => $held, 'skipped' => $skipped, 'recovered' => $recovered, 'next_at' => $nextAt,
                    'notifications_reconciled' => $replayed, 'mail' => $mail];
            }, true);
        } catch (\Throwable $error) {
            return ['scanned' => 0, 'held' => 1, 'next_at' => (int) $this->policy->now() + 60, 'reason' => 'dispatcher_busy_or_unavailable', 'error' => substr($error->getMessage(), 0, 200)];
        }
    }

    private function notifyEvent(array $event): void
    {
        if ($this->notificationController === null || array_intersect($event['reason_codes'] ?? [], ['scan_in_progress', 'resource_deferred', 'awaiting_submission', 'queue_capacity'])) { return; }
        $id = Files::id((string) $event['id']);
        $this->store->withLock('notify-' . $id, function () use ($id, $event): void {
            $directory = $this->store->directory() . '/notification-acks';
            if (!is_dir($directory) && !Files::track($directory, static fn(): bool => mkdir($directory, 0700))) {
                throw new \RuntimeException('Cannot create durable notification acknowledgments.');
            }
            Files::privateDirectory($directory);
            $path = $directory . '/' . $id . '.json';
            if (file_exists($path)) {
                if ((Files::readJson($path)['event_id'] ?? null) !== $id) { throw new \RuntimeException('Invalid notification acknowledgment.'); }
                return;
            }
            $this->notificationController->onEvent($event);
            Files::json($path, ['event_id' => $id, 'enqueued_at' => time()], true);
        }, true);
    }

    private function reconcileNotifications(int $limit): int
    {
        if ($this->notifications === null) { return 0; }
        $path = $this->store->directory() . '/notification-cursor.json';
        $cursor = file_exists($path) ? Files::readJson($path) : ['offset' => 0];
        $events = $this->store->listEvents($limit, (int) $cursor['offset']);
        $processed = 0;
        foreach ($events as $event) {
            $this->notifyEvent($event);
            ++$processed;
            // Commit after each successful idempotent enqueue, so a later failure
            // cannot skip the event that still needs recovery.
            ++$cursor['offset'];
            Files::json($path, $cursor);
        }
        if (count($events) < $limit) { Files::json($path, ['offset' => 0]); }
        return $processed;
    }

    public function runUpdates(): array
    {
        if (($this->settings['backend'] ?? 'local') !== 'local') { return ['status' => 'remote-managed']; }
        $candidate = null;
        $codeError = null;
        if (class_exists(\WPClamAV\Updates\CodeUpdateClient::class)) {
            try {
                $codeSettings = $this->settings + ['retained_storage_usage' => $this->store->usage(), 'retained_storage_root' => $this->store->directory()];
                $candidate = (new \WPClamAV\Updates\CodeUpdateClient($codeSettings, $this->policy, $this->settings['programs_dir']))->stageNext();
            }
            catch (\Throwable $error) { $codeError = substr($error->getMessage(), 0, 300); }
        }
        $result = (new NativeUpdateManager($this->settings, $this->policy))->run($candidate);
        if ($codeError !== null) { $result['code_update_error'] = $codeError; }
        Files::json($this->store->directory() . '/last-update.json', ['observed_at' => time(), 'result' => $result]);
        return $result;
    }
}

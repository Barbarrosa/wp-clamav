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
        $this->policy = new ResourcePolicy($settings);
        $policy = $this->policy;
        $this->store = new FileSubmissionStore($path, $roots,
            static fn(?int $length, array $usage, array $context) => $policy->admitStorage($path, $length, $usage, $context), $index);
        $this->policy->bindStore($this->store, $coordinator);
        $this->settings['programs_dir'] = (string) ($settings['programs_dir'] ?? '') ?: $base . '/programs';
        $state = (string) (($settings['state_dir'] ?? '') ?: ($settings['generation_dir'] ?? ''));
        if ($state === '') { $state = $base . '/native-state'; }
        $settings['state_dir'] = $state; $this->settings['state_dir'] = $state;
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

    public function runQueue(): array
    {
        $processed = 0; $held = 0;
        // Reconcile canonical pending originals even if the scheduling request was interrupted.
        // Index pagination is bounded; an explicit cursor prevents starvation behind old receipts.
        $cursorPath = $this->store->directory() . '/worker-cursor.json';
        $cursor = file_exists($cursorPath) ? Files::readJson($cursorPath) : ['offset' => 0];
        $limit = max(1, min(20, (int) ($this->settings['budgets']['worker_batch_files'] ?? 1)));
        $receipts = $this->store->list($limit, (int) $cursor['offset']);
        foreach ($receipts as $receipt) {
            try {
                $latest = $receipt['latest_event_id'] === null ? null : $this->store->latestEvent($receipt['id']);
                $interrupted = $latest !== null && ($latest['reason_codes'] ?? []) === ['scan_in_progress'];
                if (!isset($receipt['deleted_at']) && $receipt['complete'] && ($latest === null || $interrupted)) {
                    // assess() takes a nonblocking per-receipt lock. A live scan
                    // keeps that lock; an interrupted process releases it.
                    $this->intake->assess($receipt['id']); ++$processed;
                }
            } catch (\Throwable) { ++$held; }
        }
        Files::json($cursorPath, ['offset' => count($receipts) < $limit ? 0 : (int) $cursor['offset'] + count($receipts)]);
        $replayed = $this->reconcileNotifications($limit);
        $mail = $this->notifications?->runQueue() ?? [];
        return ['scanned' => $processed, 'held' => $held, 'notifications_reconciled' => $replayed, 'mail' => $mail];
    }

    private function notifyEvent(array $event): void
    {
        if ($this->notificationController === null || ($event['reason_codes'] ?? []) === ['scan_in_progress']) { return; }
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

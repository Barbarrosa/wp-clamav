<?php
declare(strict_types=1);

namespace WPClamAV\WordPress;

use WPClamAV\Application;
use WPClamAV\Contracts\SubmissionStore;
use WPClamAV\Scanning\IntakeService;

final class Plugin
{
    private static ?Application $application = null;
    private static ?int $applicationBlog = null;
    private static ?string $bootError = null;

    public static function boot(): void
    {
        add_filter('cron_schedules', [self::class, 'cronSchedules']);
        add_action('wp_clamav_scan_receipt', [self::class, 'scanReceipt'], 10, 2);
        add_action('wp_clamav_dispatch', [self::class, 'runDispatch']);
        add_action('wp_clamav_worker', [self::class, 'runWorker']);
        add_action('wp_clamav_run_updates', [self::class, 'runUpdates']);
        add_action('wp_clamav_scan_event', [self::class, 'scanEvent']);
        add_action('wp_clamav_mail_queue', [self::class, 'runMailQueue']);
        add_action('admin_post_wp_clamav_download', [self::class, 'download']);
        add_action('admin_post_nopriv_wp_clamav_download', [self::class, 'download']);
        add_action('switch_blog', [self::class, 'reset'], 10, 0);
        (new Admin())->register();
        (new CoreUploads())->register();
        (new Forminator())->register();
        (new PluginUpdates())->register();
        add_action('init', [self::class, 'initialize']);
    }

    public static function app(): Application
    {
        $blog = get_current_blog_id();
        if (self::$application === null || self::$applicationBlog !== $blog) {
            self::$application = Application::fromSettings(Settings::get());
            self::$applicationBlog = $blog;
        }
        return self::$application;
    }

    public static function intake(): IntakeService
    {
        return self::app()->intake;
    }

    public static function storage(): SubmissionStore
    {
        return self::app()->store;
    }

    public static function bootError(): ?string
    {
        return self::$bootError;
    }

    public static function reset(): void
    {
        self::$application = null;
        self::$applicationBlog = null;
        self::$bootError = null;
    }

    public static function initialize(): void
    {
        if (!Settings::enabled()) {
            return;
        }
        try {
            self::app();
            if (!wp_next_scheduled('wp_clamav_worker')) {
                wp_schedule_event(time() + 60, 'wp_clamav_halfhourly', 'wp_clamav_worker');
            }
        } catch (\Throwable $error) {
            self::$bootError = $error->getMessage();
        }
    }

    public static function cronSchedules(array $schedules): array
    {
        $schedules['wp_clamav_halfhourly'] = ['interval' => 1800, 'display' => 'WP ClamAV: every 30 minutes'];
        return $schedules;
    }

    public static function scheduleScan(string $id, ?string $profile = null, bool $explicit = false, ?int $actor = null, ?string $submissionId = null): array
    {
        $queued = self::app()->queueScan($id, $profile, $explicit, $actor, $submissionId);
        if ($queued['status'] === 'queued') { self::wakeDispatch((int) $queued['next_at']); }
        return $queued;
    }

    private static function wakeDispatch(int $at): void
    {
        try {
            self::storage()->withLock('scan-wakeup', static function () use ($at): void {
                $at = max(time() + 1, $at);
                $existing = wp_next_scheduled('wp_clamav_dispatch');
                if ($existing !== false && $existing <= $at) { return; }
                if ($existing !== false) { wp_unschedule_event($existing, 'wp_clamav_dispatch'); }
                wp_schedule_single_event($at, 'wp_clamav_dispatch');
            }, true);
        } catch (\Throwable) {
            // The durable index remains authoritative; the recurring worker
            // recovers a missed wake-up without discarding originals or intents.
            error_log('WP ClamAV dispatch wake-up deferred; durable requests remain pending.');
        }
    }

    public static function runDispatch(): void
    {
        if (!Settings::enabled()) { return; }
        try {
            $result = self::app()->runQueue();
            if (isset($result['next_at'])) { self::wakeDispatch((int) $result['next_at']); }
        } catch (\Throwable) {
            error_log('WP ClamAV bounded dispatch did not complete; retained data was not removed.');
        }
    }

    public static function scanReceipt(string $id, ?string $profile = null): void
    {
        if (!Settings::enabled()) {
            return;
        }
        try {
            // Drain legacy per-original cron events into the bounded queue.
            // A historical event carries no authenticated administrator actor.
            self::scheduleScan($id, $profile);
        } catch (\Throwable $error) {
            error_log('WP ClamAV scan could not complete for receipt ' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id));
        }
    }

    public static function runWorker(): void
    {
        if (!Settings::enabled()) {
            return;
        }
        try {
            $result = self::app()->runQueue();
            if (isset($result['next_at'])) { self::wakeDispatch((int) $result['next_at']); }
        } catch (\Throwable $error) {
            error_log('WP ClamAV worker did not complete; retained data was not removed.');
        }
        // Signature maintenance has an independent failure boundary.
        self::runUpdates();
    }

    public static function scanEvent(array $event): void
    {
        if (Settings::enabled()) { self::app()->notificationController?->onEvent($event); }
    }

    public static function runMailQueue(): array
    {
        return Settings::enabled() ? (self::app()->notifications?->runQueue() ?? []) : [];
    }

    public static function download(): never
    {
        // Resolve the current blog at invocation, rather than retaining a
        // controller bound before switch_to_blog().
        if (!Settings::enabled() || self::app()->notificationController === null) {
            wp_die(esc_html__('This review is unavailable.', 'wp-clamav'), '', ['response' => 403]);
            exit;
        }
        self::app()->notificationController->downloadAction();
    }

    public static function runUpdates(): void
    {
        if (!Settings::enabled()) {
            return;
        }
        try {
            self::app()->runUpdates();
        } catch (\Throwable $error) {
            error_log('WP ClamAV update check did not complete; the current verified generation remains selected.');
        }
    }

    public static function activate(): void
    {
        if (get_option(Settings::OPTION, null) === null) {
            add_option(Settings::OPTION, Settings::defaults(), '', false);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('wp_clamav_worker');
        wp_clear_scheduled_hook('wp_clamav_dispatch');
        // Evidence and pending scan IDs remain stored. Deactivation never deletes data.
    }
}

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

    public static function scheduleScan(string $id, ?string $profile = null): void
    {
        $args = [$id, $profile];
        if (!wp_next_scheduled('wp_clamav_scan_receipt', $args)) {
            wp_schedule_single_event(time() + 1, 'wp_clamav_scan_receipt', $args);
        }
    }

    public static function scanReceipt(string $id, ?string $profile = null): void
    {
        if (!Settings::enabled()) {
            return;
        }
        try {
            self::intake()->assess($id, $profile);
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
            self::app()->runQueue();
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
        // Evidence and pending scan IDs remain stored. Deactivation never deletes data.
    }
}

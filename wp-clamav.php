<?php
/**
 * Plugin Name: WP ClamAV
 * Description: Preserves uploads privately, records scan decisions, and integrates with Forminator.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * Text Domain: wp-clamav
 * Update URI: https://wp-clamav.invalid/updates
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WP_CLAMAV_VERSION', '0.1.0');
define('WP_CLAMAV_PLUGIN_FILE', __FILE__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'WPClamAV\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (str_contains($relative, '..')) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, [WPClamAV\WordPress\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [WPClamAV\WordPress\Plugin::class, 'deactivate']);
WPClamAV\WordPress\Plugin::boot();

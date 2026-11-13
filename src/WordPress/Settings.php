<?php
declare(strict_types=1);

namespace WPClamAV\WordPress;

final class Settings
{
    public const OPTION = 'wp_clamav_settings';

    public static function defaults(): array
    {
        $rules = function_exists('get_option') && class_exists(\WPClamAV\Notifications\NotificationManager::class)
            ? \WPClamAV\Notifications\NotificationManager::defaultRules((string) get_option('admin_email')) : [];
        return [
            'enabled' => false,
            'backend' => 'local',
            'storage_path' => '',
            'local_binary' => '',
            'database_dir' => '',
            'generation_dir' => '',
            'certificates_dir' => '',
            'state_dir' => '',
            'updater_binary' => '',
            'native_manifest' => '',
            'release_public_key' => '',
            'native_release_public_key' => '',
            'plugin_release_public_key' => '',
            'plugin_repository' => 'Barbarrosa/wp-clamav',
            'plugin_auto_updates' => true,
            'programs_dir' => '',
            'release_channel' => 'stable',
            'endpoint' => '',
            'remote_token' => '',
            'profile' => 'full',
            'forminator_forms' => [],
            'notification_rules' => $rules,
            'notification_rule_epoch' => 1,
            'budgets' => [],
        ];
    }

    public static function networkManaged(): bool
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return false;
        }
        $active = get_site_option('active_sitewide_plugins', []);
        return defined('WP_CLAMAV_PLUGIN_FILE') && isset($active[plugin_basename(WP_CLAMAV_PLUGIN_FILE)]);
    }

    public static function get(?string $key = null, mixed $fallback = null): mixed
    {
        $saved = self::networkManaged() ? get_site_option(self::OPTION, []) : get_option(self::OPTION, []);
        $all = array_replace(self::defaults(), is_array($saved) ? $saved : []);
        if (defined('WP_CLAMAV_REMOTE_TOKEN')) {
            $all['remote_token'] = (string) constant('WP_CLAMAV_REMOTE_TOKEN');
        }
        foreach (['native_release_public_key' => 'WP_CLAMAV_NATIVE_RELEASE_PUBLIC_KEY', 'plugin_release_public_key' => 'WP_CLAMAV_PLUGIN_RELEASE_PUBLIC_KEY'] as $setting => $constant) {
            if (defined($constant)) {
                $all[$setting] = (string) constant($constant);
            }
        }
        return $key === null ? $all : ($all[$key] ?? $fallback);
    }

    public static function enabled(): bool
    {
        return self::get('enabled') === true;
    }

    public static function capability(): string
    {
        return self::networkManaged() ? 'manage_network_options' : 'manage_options';
    }

    /** Invalid values are rejected, rather than silently becoming a different policy. */
    public static function sanitize(array $input): array
    {
        $out = self::defaults();
        $out['enabled'] = !empty($input['enabled']);
        $out['backend'] = (string) ($input['backend'] ?? 'local');
        if (!in_array($out['backend'], ['local', 'remote'], true)) {
            throw new \InvalidArgumentException('Choose a local or HTTPS scanning backend.');
        }
        foreach (['storage_path', 'local_binary', 'database_dir', 'generation_dir', 'certificates_dir', 'state_dir', 'updater_binary', 'native_manifest', 'programs_dir'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));
            if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new \InvalidArgumentException('Paths cannot contain control characters.');
            }
            if ($value !== '' && !str_starts_with($value, '/')) {
                throw new \InvalidArgumentException('Use absolute filesystem paths.');
            }
            $out[$key] = rtrim($value, '/');
        }
        if ($out['generation_dir'] === '') {
            $out['generation_dir'] = $out['database_dir'];
        }
        if ($out['state_dir'] === '') {
            $out['state_dir'] = $out['generation_dir'];
        }
        foreach (['release_public_key', 'native_release_public_key', 'plugin_release_public_key'] as $key) {
            $out[$key] = trim((string) ($input[$key] ?? ''));
            if (strpbrk($out[$key], "\r\n\0") !== false) {
                throw new \InvalidArgumentException('Release configuration contains invalid control characters.');
            }
            if ($key !== 'release_public_key' && $out[$key] !== '') {
                $decoded = base64_decode($out[$key], true);
                if (!is_string($decoded) || strlen($decoded) !== 32 || base64_encode($decoded) !== $out[$key]) {
                    throw new \InvalidArgumentException('Release verification keys must be standard base64 encoding of a 32-byte Ed25519 public key.');
                }
            }
        }
        $out['release_channel'] = (string) ($input['release_channel'] ?? 'stable');
        if (!in_array($out['release_channel'], ['stable', 'preview'], true)) {
            throw new \InvalidArgumentException('Choose the stable or preview release channel.');
        }
        $out['plugin_repository'] = trim((string) ($input['plugin_repository'] ?? ''));
        if ($out['plugin_repository'] !== '' && !preg_match('~\A[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\z~D', $out['plugin_repository'])) {
            throw new \InvalidArgumentException('Use the GitHub plugin repository as owner/repository.');
        }
        $out['plugin_auto_updates'] = !empty($input['plugin_auto_updates']);
        if ($out['programs_dir'] !== '') {
            \WPClamAV\Storage\Files::privateDirectory($out['programs_dir']);
            $canonical = realpath($out['programs_dir']);
            foreach (array_filter([defined('ABSPATH') ? ABSPATH : null, $_SERVER['DOCUMENT_ROOT'] ?? null]) as $root) {
                $root = realpath($root);
                if ($root !== false && ($canonical === $root || str_starts_with($canonical . '/', rtrim($root, '/') . '/'))) {
                    throw new \InvalidArgumentException('Native programs must be stored outside every website document root.');
                }
            }
        }
        $endpoint = trim((string) ($input['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $parsed = parse_url($endpoint);
            if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host'])
                || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['fragment'])) {
                throw new \InvalidArgumentException('The remote scanning endpoint must be HTTPS without embedded credentials or a fragment.');
            }
        }
        $out['endpoint'] = $endpoint;
        $stored = self::networkManaged() ? get_site_option(self::OPTION, []) : get_option(self::OPTION, []);
        $out['remote_token'] = !empty($input['clear_remote_token']) ? '' : (string) ($stored['remote_token'] ?? '');
        if (!defined('WP_CLAMAV_REMOTE_TOKEN') && isset($input['remote_token']) && trim((string) $input['remote_token']) !== '') {
            $out['remote_token'] = trim((string) $input['remote_token']);
        }
        if (preg_match('/[\r\n\x00]/', $out['remote_token'])) {
            throw new \InvalidArgumentException('The service credential contains invalid characters.');
        }
        $out['profile'] = (string) ($input['profile'] ?? 'full');
        if (!in_array($out['profile'], ['full', 'pdf-jpeg-png-pe-exclusions'], true)) {
            throw new \InvalidArgumentException('Choose a supported scan profile.');
        }
        $forms = $input['forminator_forms'] ?? [];
        if (is_string($forms)) {
            $forms = preg_split('/[\s,]+/', trim($forms), -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($forms)) {
            throw new \InvalidArgumentException('Forminator form IDs must be a list.');
        }
        foreach ($forms as $id) {
            if (!ctype_digit((string) $id) || (int) $id < 1) {
                throw new \InvalidArgumentException('Forminator form IDs must be positive integers.');
            }
            $out['forminator_forms'][] = (int) $id;
        }
        $out['forminator_forms'] = array_values(array_unique($out['forminator_forms']));
        $rules = $input['notification_rules'] ?? [];
        if (is_string($rules)) {
            $rules = trim($rules) === '' ? [] : json_decode($rules, true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($rules) || !array_is_list($rules)) {
            throw new \InvalidArgumentException('Notification rules must be a JSON list.');
        }
        if (class_exists(\WPClamAV\Notifications\NotificationManager::class)) {
            $rules = \WPClamAV\Notifications\NotificationManager::validateRules($rules);
        }
        $out['notification_rules'] = $rules;
        $out['notification_rule_epoch'] = max(1, (int) self::get('notification_rule_epoch', 1));
        if ($rules !== self::get('notification_rules', [])) {
            ++$out['notification_rule_epoch'];
        }
        $budgets = $input['budgets'] ?? [];
        if (is_string($budgets)) {
            $budgets = trim($budgets) === '' ? [] : json_decode($budgets, true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($budgets) || ($budgets !== [] && array_is_list($budgets))) {
            throw new \InvalidArgumentException('Resource budgets must be a JSON object.');
        }
        foreach ($budgets as $name => $value) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]*$/', $name)
                || !(is_int($value) || is_float($value)) || !is_finite((float) $value) || $value < 0) {
                throw new \InvalidArgumentException('Resource budgets require named, finite, nonnegative numbers.');
            }
        }
        $out['budgets'] = $budgets;
        if ($out['enabled'] && $out['storage_path'] === '') {
            throw new \InvalidArgumentException('Configure private storage before enabling protection.');
        }
        if ($out['enabled'] && $out['backend'] === 'remote' && $out['endpoint'] === '') {
            throw new \InvalidArgumentException('Configure the HTTPS scanning endpoint before enabling remote scans.');
        }
        return $out;
    }

    public static function save(array $settings): void
    {
        if (self::networkManaged()) {
            update_site_option(self::OPTION, $settings);
        } else {
            update_option(self::OPTION, $settings, false);
        }
    }
}

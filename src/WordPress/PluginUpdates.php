<?php
declare(strict_types=1);

namespace WPClamAV\WordPress;

use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Storage\Files;

/** WordPress owns installation; only authenticated GitHub ZIPs reach its unpacker. */
final class PluginUpdates
{
    public const UPDATE_URI = 'https://wp-clamav.invalid/updates';
    private ?\Closure $http;
    private ?float $deadline = null;
    private ?string $attemptKey = null;

    public function __construct(private ?array $settings = null, ?callable $http = null)
    {
        $this->http = $http ? \Closure::fromCallable($http) : null;
    }

    public function register(): void
    {
        add_filter('update_plugins_wp-clamav.invalid', [$this, 'offer'], 10, 4);
        add_filter('upgrader_pre_download', [$this, 'download'], PHP_INT_MAX, 4);
        add_filter('auto_update_plugin', [$this, 'automatic'], 20, 2);
    }

    public function offer(mixed $update, array $plugin, string $file, array $locales = []): mixed
    {
        if ($file !== $this->pluginFile()) { return $update; }
        $this->deadline = null;
        try {
            $release = $this->release();
            if ($release === null || !version_compare($release['manifest']['version'], (string) ($plugin['Version'] ?? WP_CLAMAV_VERSION), '>')) { return false; }
            $s = $this->config();
            return ['id' => self::UPDATE_URI, 'slug' => 'wp-clamav', 'version' => $release['manifest']['version'],
                'url' => 'https://github.com/' . $s['plugin_repository'], 'package' => $release['package']['url'],
                'requires_php' => '8.2', 'requires' => '6.4', 'autoupdate' => !empty($s['plugin_auto_updates'])];
        } catch (\Throwable $error) {
            $this->health($error->getMessage());
            return false;
        }
    }

    public function automatic(mixed $allowed, mixed $item): mixed
    {
        if (!is_object($item) || ($item->plugin ?? '') !== $this->pluginFile()) { return $allowed; }
        $s = $this->config();
        return $this->configured($s) && !empty($s['plugin_auto_updates']) && ($item->id ?? '') === self::UPDATE_URI;
    }

    public function download(mixed $reply, string $url, mixed $upgrader = null, array $extra = []): mixed
    {
        if (($extra['plugin'] ?? '') !== $this->pluginFile()) { return $reply; }
        $this->deadline = null;
        $path = null;
        try {
            $release = $this->release();
            if ($release === null || !hash_equals($release['package']['url'], $url)) {
                throw new \RuntimeException('This plugin package has no matching authenticated release.');
            }
            $limits = $this->limits();
            $size = $release['package']['size'];
            if ($size * 1.2 > $limits['staging_bytes']) { throw new \RuntimeException('The plugin ZIP exceeds its confirmed staging allocation.'); }
            if (!function_exists('wp_tempnam')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
            $path = wp_tempnam('wp-clamav-signed-update.zip');
            if (!is_string($path) || $path === '' || !chmod($path, 0600)) { throw new \RuntimeException('Cannot create a protected plugin staging file.'); }
            $free = disk_free_space(dirname($path));
            if ($free === false || $free < $size * 1.2) { throw new \RuntimeException('Insufficient plugin staging disk space.'); }
            $response = $this->get($url, $size + 1, $limits['seconds'], ['stream' => true, 'filename' => $path]);
            if (wp_remote_retrieve_response_code($response) !== 200 || filesize($path) !== $size
                || !hash_equals($release['package']['sha256'], hash_file('sha256', $path))) {
                throw new \RuntimeException('The downloaded plugin ZIP does not match its signed identity.');
            }
            $this->checkArchive($path, $limits['staging_bytes'], $limits['metadata_bytes']);
            return $path;
        } catch (\Throwable $error) {
            if (is_string($path) && is_file($path)) { unlink($path); }
            $this->health($error->getMessage());
            return new \WP_Error('wp_clamav_unsigned_plugin_update', 'WP ClamAV update was held: ' . $error->getMessage());
        }
    }

    private function config(): array { return $this->settings ?? Settings::get(); }
    private function pluginFile(): string { return plugin_basename(WP_CLAMAV_PLUGIN_FILE); }
    private function configured(array $s): bool
    {
        return ($s['plugin_repository'] ?? '') !== '' && ($s['plugin_release_public_key'] ?? '') !== '';
    }

    private function limits(): array
    {
        $s = $this->config();
        $b = $s['budgets'] ?? [];
        $size = $b['code_staging_bytes'] ?? null;
        $seconds = $b['code_update_seconds'] ?? null;
        $account = $b['account_remaining_bytes'] ?? null;
        $reserve = $b['storage_reserve_bytes'] ?? null;
        $updates = $b['update_reserve_bytes'] ?? null;
        foreach ([$size, $seconds, $account, $reserve, $updates] as $value) {
            if (!is_numeric($value) || !is_finite((float) $value) || $value < 0) {
                throw new \RuntimeException('Confirm code staging, work deadlines, account quota and storage reserves before enabling signed plugin updates.');
            }
        }
        $policy = new ResourcePolicy($s);
        $network = $policy->remoteLimits(PHP_SAPI !== 'cli');
        $effective = min((float) $size, max(0, (float) $account - (float) $reserve - (float) $updates));
        $seconds = min((float) $seconds, $network['wall_seconds']);
        if ($effective < 1 || $seconds < 1) { throw new \RuntimeException('No bounded plugin update capacity remains.'); }
        return ['staging_bytes' => (int) $effective, 'seconds' => max(1, (int) floor($seconds)),
            'metadata_bytes' => min(1048576, $network['output_bytes'])];
    }

    private function release(): ?array
    {
        $s = $this->config();
        if (!$this->configured($s)) { return null; }
        $repo = (string) $s['plugin_repository'];
        if (!preg_match('~\A[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\z~D', $repo)) {
            throw new \RuntimeException('Invalid configured plugin repository.');
        }
        $key = base64_decode((string) $s['plugin_release_public_key'], true);
        if (!is_string($key) || strlen($key) !== 32 || base64_encode($key) !== $s['plugin_release_public_key'] || !function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException('A valid Ed25519 plugin trust key and sodium are required.');
        }
        $channel = $s['release_channel'] ?? 'stable';
        if (!in_array($channel, ['stable', 'preview'], true)) { throw new \RuntimeException('Invalid plugin release channel.'); }
        $limits = $this->limits();
        $this->deadline ??= microtime(true) + $limits['seconds'];
        $fingerprint = substr(hash('sha256', $repo . ':' . $channel . ':' . $key), 0, 40);
        $cacheKey = 'wp_clamav_plugin_release_' . $fingerprint;
        $this->attemptKey = 'wp_clamav_plugin_attempt_' . $fingerprint;
        $cached = get_site_transient($cacheKey);
        if (is_array($cached) && isset($cached['exact'], $cached['signature'])) {
            try { return $this->verify($cached['exact'], base64_decode($cached['signature'], true) ?: '', $repo, $channel, $key); }
            catch (\Throwable) { delete_site_transient($cacheKey); }
        }
        // A failed discovery is spaced too; invalid releases cannot cause a request loop.
        $attemptKey = $this->attemptKey;
        $next = (int) get_site_option($attemptKey, 0);
        if ($next > time()) { return null; }
        $interval = max(7200, (int) ($s['budgets']['provider_cron_interval_seconds'] ?? 1800), (int) ($s['budgets']['update_interval_seconds'] ?? 0));
        update_site_option($attemptKey, time() + $interval);
        $api = 'https://api.github.com/repos/' . $repo . '/releases';
        $response = $this->get($api . ($channel === 'stable' ? '/latest' : '?per_page=100'), $limits['metadata_bytes'], $limits['seconds']);
        if (wp_remote_retrieve_response_code($response) !== 200) { throw new \RuntimeException('GitHub plugin release discovery failed.'); }
        $data = json_decode(wp_remote_retrieve_body($response), true, 32, JSON_THROW_ON_ERROR);
        if ($channel === 'preview') {
            $data = array_values(array_filter(is_array($data) ? $data : [], static fn($r): bool => is_array($r) && empty($r['draft']) && ($r['prerelease'] ?? false) === true));
            usort($data, static fn($a, $b): int => strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? '')));
            $data = $data[0] ?? null;
        }
        if (!is_array($data) || !empty($data['draft']) || ($channel === 'stable' && !empty($data['prerelease']))
            || !is_string($data['tag_name'] ?? null) || !preg_match('/\Av[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?\z/D', $data['tag_name'])) {
            throw new \RuntimeException('No compatible published plugin release was found.');
        }
        $base = 'https://github.com/' . $repo . '/releases/download/' . $data['tag_name'] . '/';
        $assets = [];
        foreach ($data['assets'] ?? [] as $asset) {
            if (is_array($asset) && isset($asset['name'])) { $assets[$asset['name']] = $asset['browser_download_url'] ?? ''; }
        }
        foreach (['manifest.json', 'manifest.sig'] as $name) {
            if (($assets[$name] ?? '') !== $base . $name) { throw new \RuntimeException('The plugin release lacks its expected signed metadata assets.'); }
        }
        $manifestResponse = $this->get($base . 'manifest.json', $limits['metadata_bytes'], $limits['seconds']);
        $signatureResponse = $this->get($base . 'manifest.sig', 65, $limits['seconds']);
        if (wp_remote_retrieve_response_code($manifestResponse) !== 200 || wp_remote_retrieve_response_code($signatureResponse) !== 200) {
            throw new \RuntimeException('Signed plugin release metadata could not be fetched.');
        }
        $exact = wp_remote_retrieve_body($manifestResponse);
        $signature = wp_remote_retrieve_body($signatureResponse);
        $release = $this->verify($exact, $signature, $repo, $channel, $key);
        if (($release['manifest']['tag'] ?? '') !== $data['tag_name']) { throw new \RuntimeException('Plugin manifest does not match its discovered release tag.'); }
        if (($assets[basename($release['package']['url'])] ?? '') !== $release['package']['url']) {
            throw new \RuntimeException('The authenticated plugin ZIP is absent from this release.');
        }
        set_site_transient($cacheKey, ['exact' => $exact, 'signature' => base64_encode($signature)], min($interval, max(1, $release['manifest']['expires_at'] - time())));
        $this->health(null);
        return $release;
    }

    private function verify(string $exact, string $signature, string $repo, string $channel, string $key): array
    {
        if (strlen($exact) > 1048576 || strlen($signature) !== 64 || !sodium_crypto_sign_verify_detached($signature, $exact, $key)) {
            throw new \RuntimeException('The plugin release signature is invalid.');
        }
        $m = json_decode($exact, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($m) || ($m['protocol'] ?? null) !== 1 || ($m['repository'] ?? '') !== $repo || ($m['channel'] ?? '') !== $channel
            || ($m['platform'] ?? '') !== 'wordpress-php-8.2+' || !is_int($m['sequence'] ?? null) || $m['sequence'] < 1
            || !is_int($m['expires_at'] ?? null) || $m['expires_at'] <= time()
            || !is_string($m['version'] ?? null) || !preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?\z/D', $m['version'])
            || ($m['tag'] ?? '') !== 'v' . $m['version']) { throw new \RuntimeException('The plugin release is expired or incompatible with the configured channel.'); }
        $files = $m['files'] ?? null;
        if (!is_array($files) || !array_is_list($files) || count($files) !== 1) { throw new \RuntimeException('The plugin release must identify exactly one ZIP.'); }
        $file = $files[0];
        $name = 'wp-clamav-' . $m['version'] . '.zip';
        if (!is_array($file) || ($file['url'] ?? '') !== 'https://github.com/' . $repo . '/releases/download/' . $m['tag'] . '/' . $name
            || basename((string) ($file['path'] ?? '')) !== $name || !is_int($file['size'] ?? null) || $file['size'] < 1
            || !preg_match('/\A[a-f0-9]{64}\z/D', (string) ($file['sha256'] ?? ''))) { throw new \RuntimeException('The signed plugin ZIP identity is invalid.'); }
        $s = $this->config();
        $root = (string) (($s['programs_dir'] ?? '') ?: ($s['storage_path'] ?? ''));
        Files::privateDirectory($root);
        $floorPath = $root . '/plugin-floor-' . substr(hash('sha256', $repo . ':' . $channel . ':' . $key), 0, 40) . '.json';
        $lockPath = $floorPath . '.lock';
        if (file_exists($lockPath)) { Files::regular($lockPath); }
        $old = umask(0077);
        try { $lock = fopen($lockPath, 'c+b'); } finally { umask($old); }
        if ($lock === false) { throw new \RuntimeException('Cannot lock the plugin release replay floor.'); }
        try {
            Files::regular($lockPath);
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('Another plugin release check is recording its replay floor.'); }
            $floor = file_exists($floorPath) ? Files::readJson($floorPath) : ['sequence' => 0, 'hash' => ''];
            $hash = hash('sha256', $exact);
            if ($m['sequence'] < $floor['sequence'] || ($m['sequence'] === $floor['sequence'] && $floor['hash'] !== $hash)) {
                throw new \RuntimeException('A replayed or replaced plugin release was rejected.');
            }
            if ($m['sequence'] > $floor['sequence']) { Files::json($floorPath, ['sequence' => $m['sequence'], 'hash' => $hash]); }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        return ['manifest' => $m, 'package' => $file];
    }

    private function checkArchive(string $path, int $capacity, int $metadataBound): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) { throw new \RuntimeException('Cannot inspect the authenticated plugin ZIP.'); }
        try {
            fseek($stream, max(0, filesize($path) - 65557));
            $tail = stream_get_contents($stream, 65557);
        } finally { fclose($stream); }
        $offset = is_string($tail) ? strrpos($tail, "PK\x05\x06") : false;
        if ($offset === false || strlen($tail) - $offset < 22) { throw new \RuntimeException('Plugin ZIP central directory is missing.'); }
        $directory = unpack('vdisk/vstart/ventries_disk/ventries/Vsize/Voffset/vcomment', substr($tail, $offset + 4, 18));
        $estimate = strlen($tail) + $directory['size'] * 3 + $directory['entries'] * 2048;
        if ($directory['disk'] !== 0 || $directory['start'] !== 0 || $directory['entries'] !== $directory['entries_disk']
            || $directory['entries'] > 512 || $directory['size'] > $metadataBound
            || $estimate > (new ResourcePolicy($this->config()))->phpHeadroom()) {
            throw new \RuntimeException('Plugin ZIP inventory exceeds the bounded PHP inspection allowance.');
        }
        // PclZip is bundled with WordPress, including sites without PHP's ZIP extension.
        if (!class_exists('PclZip')) { require_once ABSPATH . 'wp-admin/includes/class-pclzip.php'; }
        $entries = (new \PclZip($path))->listContent();
        if (!is_array($entries) || $entries === [] || count($entries) > 512) { throw new \RuntimeException('Plugin ZIP inventory is invalid or exceeds its bound.'); }
        $unpacked = 0;
        $found = false;
        foreach ($entries as $entry) {
            $name = (string) ($entry['filename'] ?? '');
            if (!str_starts_with($name, 'wp-clamav/') || str_contains($name, '..') || str_contains($name, '\\')
                || str_contains($name, '//') || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                throw new \RuntimeException('Plugin ZIP contains an unsafe installation path.');
            }
            $size = $entry['size'] ?? null;
            if (!is_int($size) || $size < 0 || $unpacked > PHP_INT_MAX - $size) { throw new \RuntimeException('Plugin ZIP expansion size is invalid.'); }
            $unpacked += $size;
            $found = $found || $name === 'wp-clamav/wp-clamav.php';
        }
        $backup = 0;
        $count = 0;
        $installed = realpath(dirname(WP_CLAMAV_PLUGIN_FILE));
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($installed, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if (++$count > 4096 || $entry->isLink()) { throw new \RuntimeException('The installed plugin exceeds the bounded rollback inventory.'); }
            if ($entry->isFile()) { $backup += $entry->getSize(); }
        }
        // ZIP, unpacked tree and WordPress's existing-plugin rollback copy coexist.
        $peak = filesize($path) + $unpacked + $backup;
        if (!$found || $peak < 0 || $peak * 1.2 > $capacity) { throw new \RuntimeException('The authenticated plugin needs more staging and rollback capacity than allocated.'); }
        $free = disk_free_space(WP_CONTENT_DIR);
        if ($free === false || $free < $peak * 1.2) { throw new \RuntimeException('Insufficient WordPress update and rollback disk space.'); }
    }

    private function get(string $url, int $maxBytes, int $seconds, array $extra = []): array
    {
        for ($redirects = 0; ; ++$redirects) {
            $parsed = parse_url($url);
            if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https' || isset($parsed['user']) || isset($parsed['pass'])
                || isset($parsed['fragment']) || (isset($parsed['port']) && $parsed['port'] !== 443)
                || !in_array(strtolower($parsed['host'] ?? ''), ['api.github.com', 'github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com'], true)) {
                throw new \RuntimeException('A plugin update redirected outside the approved HTTPS release hosts.');
            }
            if ($this->deadline !== null) {
                $remaining = $this->deadline - microtime(true);
                if ($remaining < 1) { throw new \RuntimeException('Plugin update work deadline exhausted.'); }
                $seconds = min($seconds, (int) floor($remaining));
            }
            $args = array_replace(['timeout' => $seconds, 'redirection' => 0, 'sslverify' => true, 'reject_unsafe_urls' => true,
                'limit_response_size' => $maxBytes, 'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'WP-ClamAV/' . WP_CLAMAV_VERSION]], $extra);
            $response = $this->http !== null ? ($this->http)($url, $args) : wp_safe_remote_get($url, $args);
            if (is_wp_error($response) || !is_array($response)) { throw new \RuntimeException('The bounded HTTPS plugin update request failed.'); }
            if (!isset($extra['stream']) && strlen(wp_remote_retrieve_body($response)) > $maxBytes) { throw new \RuntimeException('Plugin release metadata exceeded its bound.'); }
            $status = wp_remote_retrieve_response_code($response);
            if (in_array($status, [403, 429, 503], true) && $this->attemptKey !== null) {
                $retry = wp_remote_retrieve_header($response, 'retry-after');
                $reset = wp_remote_retrieve_header($response, 'x-ratelimit-reset');
                $until = is_string($retry) && ctype_digit($retry) ? time() + (int) $retry : (is_string($retry) ? strtotime($retry) : false);
                if (is_string($reset) && ctype_digit($reset)) { $until = max((int) $until, (int) $reset); }
                if ($until !== false && $until > time()) { update_site_option($this->attemptKey, max((int) get_site_option($this->attemptKey, 0), $until)); }
            }
            if (!in_array($status, [301, 302, 303, 307, 308], true)) { return $response; }
            if ($redirects >= 3) { throw new \RuntimeException('Plugin release redirect bound exceeded.'); }
            $location = wp_remote_retrieve_header($response, 'location');
            if (!is_string($location) || $location === '') { throw new \RuntimeException('Plugin release redirect has no location.'); }
            $url = $location;
        }
    }

    private function health(?string $message): void
    {
        update_site_option('wp_clamav_plugin_update_health', ['checked_at' => time(), 'status' => $message === null ? 'verified' : 'held', 'reason' => $message]);
    }
}

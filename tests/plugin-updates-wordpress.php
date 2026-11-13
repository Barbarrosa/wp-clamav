<?php
declare(strict_types=1);

/** Actual WordPress update filters, with HTTPS responses supplied by an isolated fixture. */
$wordpress = getenv('WP_CLAMAV_WORDPRESS_DIR');
if (!$wordpress || !is_file($wordpress . '/wp-load.php')) {
    echo json_encode(['suite' => 'plugin-updates-wordpress', 'result' => 'skipped', 'reason' => 'Set WP_CLAMAV_WORDPRESS_DIR to the disposable installed test site.']) . "\n";
    exit(0);
}
require $wordpress . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

use WPClamAV\WordPress\PluginUpdates;
use WPClamAV\WordPress\Settings;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
};
$root = sys_get_temp_dir() . '/wp-clamav-plugin-release-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/source', 0700);
mkdir($root . '/source/wp-clamav', 0700);
file_put_contents($root . '/source/wp-clamav/wp-clamav.php', "<?php\n/* Plugin Name: WP ClamAV\nVersion: 0.2.0\n*/\n");
file_put_contents($root . '/source/wp-clamav/README.md', "Signed fixture.\n");
$archive = new PclZip($root . '/fixture.zip');
if (!$archive->create($root . '/source/wp-clamav', PCLZIP_OPT_REMOVE_PATH, $root . '/source')) {
    throw new RuntimeException('Could not construct fixture ZIP.');
}
$zip = file_get_contents($root . '/fixture.zip');
$pair = sodium_crypto_sign_keypair();
$public = sodium_crypto_sign_publickey($pair);
$secret = sodium_crypto_sign_secretkey($pair);
$repo = 'IntegrationFixture/wp-clamav-' . bin2hex(random_bytes(6));
$base = 'https://github.com/' . $repo . '/releases/download/v0.2.0/';
$config = array_replace(Settings::defaults(), ['storage_path' => $root, 'plugin_repository' => $repo,
    'plugin_release_public_key' => base64_encode($public), 'plugin_auto_updates' => true,
    'budgets' => ['code_staging_bytes' => 16777216, 'code_update_seconds' => 12,
        'account_remaining_bytes' => 67108864, 'storage_reserve_bytes' => 1048576, 'update_reserve_bytes' => 1048576,
        'request_work_seconds' => 15, 'worker_wall_seconds' => 15, 'cleanup_seconds' => 2, 'php_work_bytes' => 67108864]]);
$manifest = ['protocol' => 1, 'repository' => $repo, 'channel' => 'stable', 'sequence' => 1,
    'expires_at' => time() + 3600, 'version' => '0.2.0', 'platform' => 'wordpress-php-8.2+', 'tag' => 'v0.2.0',
    'files' => [['path' => 'wp-clamav-0.2.0.zip', 'url' => $base . 'wp-clamav-0.2.0.zip', 'size' => strlen($zip), 'sha256' => hash('sha256', $zip)]]];
$state = (object) ['manifest' => $manifest, 'calls' => [], 'badSignature' => false, 'corruptZip' => false];
$fingerprint = substr(hash('sha256', $repo . ':stable:' . $public), 0, 40);
$cacheKey = 'wp_clamav_plugin_release_' . $fingerprint;
$attemptKey = 'wp_clamav_plugin_attempt_' . $fingerprint;
$reset = static function () use ($cacheKey, $attemptKey): void {
    delete_site_transient($cacheKey); delete_site_option($attemptKey);
};
$http = static function (string $url, array $args) use ($state, $secret, $base, $repo, $zip, $check): array {
    $state->calls[] = $url;
    $check(str_starts_with($url, 'https://') && ($args['sslverify'] ?? false) === true, 'HTTPS certificate verification is mandatory');
    $check(($args['timeout'] ?? 0) >= 1 && ($args['timeout'] ?? 999) <= 12, 'each HTTP request uses the bounded work deadline');
    $exact = json_encode($state->manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if ($url === 'https://api.github.com/repos/' . $repo . '/releases/latest') {
        $body = json_encode(['tag_name' => 'v0.2.0', 'draft' => false, 'prerelease' => false,
            'assets' => array_map(static fn(string $name): array => ['name' => $name, 'browser_download_url' => $base . $name],
                ['manifest.json', 'manifest.sig', 'wp-clamav-0.2.0.zip'])]);
    } elseif ($url === $base . 'manifest.json') { $body = $exact;
    } elseif ($url === $base . 'manifest.sig') {
        $body = $state->badSignature ? str_repeat('x', 64) : sodium_crypto_sign_detached($exact, $secret);
    } elseif ($url === $base . 'wp-clamav-0.2.0.zip') {
        $body = $state->corruptZip ? str_repeat('x', strlen($zip)) : $zip;
    } else { throw new RuntimeException('Unexpected fixture URL: ' . $url); }
    $check(strlen($body) <= $args['limit_response_size'], 'HTTP response length stays within its declared bound');
    if (!empty($args['stream'])) { file_put_contents($args['filename'], $body); $body = ''; }
    return ['response' => ['code' => 200], 'body' => $body, 'headers' => []];
};
$client = new PluginUpdates($config, $http);
$file = plugin_basename(WP_CLAMAV_PLUGIN_FILE);
$oldHooks = [];
$health = get_site_option('wp_clamav_plugin_update_health', null);
try {
    foreach (['update_plugins_wp-clamav.invalid', 'upgrader_pre_download', 'auto_update_plugin'] as $hook) {
        $oldHooks[$hook] = $GLOBALS['wp_filter'][$hook] ?? null;
        remove_all_filters($hook);
    }
    $client->register();
    $offer = apply_filters('update_plugins_wp-clamav.invalid', false, ['Version' => WP_CLAMAV_VERSION], $file, ['en_US']);
    $check(is_array($offer) && $offer['version'] === '0.2.0' && $offer['package'] === $base . 'wp-clamav-0.2.0.zip', 'real update URI filter offers the authenticated release');
    $check(count($state->calls) === 3, 'discovery reads only API, exact manifest and raw signature');
    $again = $client->offer(false, ['Version' => WP_CLAMAV_VERSION], $file);
    $check($again === $offer && count($state->calls) === 3, 'signed cached metadata is reverified without another API request');
    $item = (object) ['plugin' => $file, 'id' => PluginUpdates::UPDATE_URI];
    $check(apply_filters('auto_update_plugin', false, $item) === true, 'automatic updates require the configured authenticated plugin identity');
    $path = apply_filters('upgrader_pre_download', false, $offer['package'], null, ['plugin' => $file]);
    $check(is_string($path) && is_file($path) && hash_file('sha256', $path) === hash('sha256', $zip), 'upgrader receives only the ZIP with its exact signed bytes' . (is_wp_error($path) ? ': ' . $path->get_error_message() : ''));
    $check((fileperms($path) & 0777) === 0600, 'downloaded program ZIP staging is owner-only');
    unlink($path);
    $check(is_wp_error($client->download(false, 'https://example.test/untrusted.zip', null, ['plugin' => $file])), 'unmatched package cannot reach the WordPress unpacker');
    $state->corruptZip = true;
    $check(is_wp_error($client->download(false, $offer['package'], null, ['plugin' => $file])), 'corrupted ZIP is rejected before extraction');
    $state->corruptZip = false;

    $badCases = ['bad signature', 'expired release', 'wrong channel', 'wrong repository', 'wrong platform', 'foreign ZIP URL'];
    foreach ($badCases as $case) {
        $reset(); $state->manifest = $manifest; $state->badSignature = false;
        if ($case === 'bad signature') { $state->badSignature = true; }
        if ($case === 'expired release') { $state->manifest['expires_at'] = time() - 1; }
        if ($case === 'wrong channel') { $state->manifest['channel'] = 'preview'; }
        if ($case === 'wrong repository') { $state->manifest['repository'] = 'Another/Repository'; }
        if ($case === 'wrong platform') { $state->manifest['platform'] = 'linux-x86_64-glibc2.36'; }
        if ($case === 'foreign ZIP URL') { $state->manifest['files'][0]['url'] = 'https://example.test/plugin.zip'; }
        $check($client->offer(false, ['Version' => WP_CLAMAV_VERSION], $file) === false, $case . ' is held');
        $calls = count($state->calls);
        $check($client->offer(false, ['Version' => WP_CLAMAV_VERSION], $file) === false && count($state->calls) === $calls, 'failed checks observe the persisted retry interval');
    }
    $reset(); $state->manifest = $manifest; $state->manifest['sequence'] = 2; $state->badSignature = false;
    $check(is_array($client->offer(false, ['Version' => WP_CLAMAV_VERSION], $file)), 'a newer signed sequence advances the replay floor');
    $reset(); $state->manifest = $manifest;
    $check($client->offer(false, ['Version' => WP_CLAMAV_VERSION], $file) === false, 'older signed sequence cannot roll back the release floor');
    $calls = count($state->calls);
    $unconfigured = new PluginUpdates(array_replace($config, ['plugin_release_public_key' => '']), $http);
    $check($unconfigured->offer(false, ['Version' => WP_CLAMAV_VERSION], $file) === false && count($state->calls) === $calls, 'unconfigured trust key performs no network request');
    $check($unconfigured->automatic(true, $item) === false, 'unconfigured trust disables automatic plugin installation');
    $check($client->offer('preserved', [], 'unrelated/plugin.php') === 'preserved', 'unrelated plugin updates retain their existing filters');
    $check($client->download('preserved', 'https://example.test/plugin.zip', null, ['plugin' => 'unrelated/plugin.php']) === 'preserved', 'unrelated plugin downloads remain unchanged');
    echo json_encode(['suite' => 'plugin-updates-wordpress', 'checks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
} finally {
    $reset();
    if ($health === null) { delete_site_option('wp_clamav_plugin_update_health'); } else { update_site_option('wp_clamav_plugin_update_health', $health); }
    foreach ($oldHooks as $hook => $saved) {
        unset($GLOBALS['wp_filter'][$hook]);
        if ($saved !== null) { $GLOBALS['wp_filter'][$hook] = $saved; }
    }
    $remove = static function (string $path) use (&$remove): void {
        foreach (new DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) { continue; }
            if ($entry->isDir() && !$entry->isLink()) { $remove($entry->getPathname()); } else { unlink($entry->getPathname()); }
        }
        rmdir($path);
    };
    $remove($root);
}

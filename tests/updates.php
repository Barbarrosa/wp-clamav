<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'WPClamAV\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
use WPClamAV\Updates\ReleaseInstaller;
use WPClamAV\Updates\NativeUpdateManager;
use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Storage\Files;
use WPClamAV\Storage\FileSubmissionStore;

$count = 0;
function updateCheck(bool $value, string $message): void { global $count; ++$count; if (!$value) { throw new RuntimeException('FAIL: ' . $message); } }
function updateReject(callable $fn, string $message): void { try { $fn(); } catch (Throwable) { updateCheck(true, $message); return; } updateCheck(false, $message); }
function updateRemove(string $path): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (new DirectoryIterator($path) as $e) { if (!$e->isDot()) { updateRemove($e->getPathname()); } }
    rmdir($path);
}
$temp = sys_get_temp_dir() . '/wp-clamav-updates-' . bin2hex(random_bytes(12)); mkdir($temp, 0700);
try {
    $keys = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keys); $public = sodium_crypto_sign_publickey($keys);
    $bodies = ['bin/compact-scan' => "#!/bin/sh\nexit 0\n", 'bin/compact-update' => "#!/bin/sh\nexit 0\n",
        'lib/compact-scan.real' => 'native scan ELF placeholder for installer tests', 'notices/LICENSE' => 'test license'];
    $manifest = ['protocol' => 1, 'version' => '0.1.0', 'sequence' => 1, 'expires_at' => 1700003600,
        'channel' => 'preview', 'platform' => 'linux-x86_64-glibc2.36', 'files' => []];
    foreach ($bodies as $path => $body) {
        $manifest['files'][] = ['path' => $path, 'size' => strlen($body), 'sha256' => hash('sha256', $body),
            'mode' => str_starts_with($path, 'bin/') || str_ends_with($path, '.real') ? '0500' : '0400',
            'url' => 'https://github.com/Barbarrosa/clamav/releases/download/compact-v0.1.0/' . rawurlencode($path)];
    }
    $downloadCount = 0;
    $download = function (string $url, string $path, int $size, int $timeout) use (&$downloadCount, $bodies): void {
        ++$downloadCount;
        $key = rawurldecode(basename($url));
        $old = umask(0077); try { $f = fopen($path, 'xb'); } finally { umask($old); }
        Files::writeAll($f, $bodies[$key]); Files::sync($f); fclose($f);
    };
    $installer = new ReleaseInstaller($temp, $public, 'preview', 1000000, 10, $download);
    $encode = fn(array $m): string => json_encode($m, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    $raw = $encode($manifest); $signature = sodium_crypto_sign_detached($raw, $secret);
    updateReject(fn() => $installer->stage($raw, str_repeat('x', 64), 1700000000), 'invalid signature never downloads');
    updateCheck($downloadCount === 0, 'signature verified before trusting URLs');
    $wrongChannel = $manifest; $wrongChannel['channel'] = 'stable'; $wrongRaw = $encode($wrongChannel);
    updateReject(fn() => $installer->stage($wrongRaw, sodium_crypto_sign_detached($wrongRaw, $secret), 1700000000), 'preview key cannot cross channel');
    updateReject(fn() => $installer->stage($raw, $signature, 1700003600), 'expired release refused');
    $unsafe = $manifest; $unsafe['files'][0]['path'] = 'bin/../../public.php'; $unsafeRaw = $encode($unsafe);
    updateReject(fn() => $installer->stage($unsafeRaw, sodium_crypto_sign_detached($unsafeRaw, $secret), 1700000000), 'signed path traversal refused');
    updateCheck($downloadCount === 0, 'invalid manifest still never downloads');
    $result = $installer->stage($raw, $signature, 1700000000);
    updateCheck($result['id'] === 'pkg-' . hash('sha256', $raw), 'exact signed bytes identify code package');
    updateCheck(file_get_contents($result['path'] . '/manifest.exact') === $raw, 'exact manifest retained without canonicalization drift');
    updateCheck((fileperms($result['path'] . '/bin/compact-scan') & 0777) === 0500, 'private launcher executable');
    updateCheck((fileperms($result['path'] . '/lib/compact-scan.real') & 0777) === 0500, 'native real executable remains executable');
    updateCheck((fileperms($result['path'] . '/notices/LICENSE') & 0777) === 0400, 'required licenses retained privately');
    updateCheck(!file_exists($temp . '/current.json'), 'staging code does not activate mixed engine/database tuple');
    $before = $downloadCount;
    updateCheck($installer->stage($raw, $signature, 1700000000)['id'] === $result['id'] && $downloadCount === $before, 'idempotent installation revalidates without redownloading');
    $replacement = $manifest; $replacement['version'] = '0.1.1'; $replacementRaw = $encode($replacement);
    updateReject(fn() => $installer->stage($replacementRaw, sodium_crypto_sign_detached($replacementRaw, $secret), 1700000000), 'accepted sequence cannot be replaced');
    $new = $replacement; $new['sequence'] = 2; $newRaw = $encode($new);
    $installer->stage($newRaw, sodium_crypto_sign_detached($newRaw, $secret), 1700000000);
    updateReject(fn() => $installer->stage($raw, $signature, 1700000000), 'older signed release replay refused');
    $changed = $temp . '/releases/pkg-' . hash('sha256', $newRaw) . '/bin/compact-scan';
    chmod($changed, 0600); file_put_contents($changed, 'tampered'); chmod($changed, 0500);
    updateReject(fn() => $installer->stage($newRaw, sodium_crypto_sign_detached($newRaw, $secret), 1700000000), 'existing native program tampering detected');
    mkdir($temp . '/small', 0700);
    $small = new ReleaseInstaller($temp . '/small', $public, 'preview', 1, 10, $download);
    updateReject(fn() => $small->stage($raw, $signature, 1700000000), 'staging allocation not silently raised');
    mkdir($temp . '/bad-download', 0700);
    $bad = new ReleaseInstaller($temp . '/bad-download', $public, 'preview', 1000000, 10,
        static function ($url, $path) { file_put_contents($path, 'bad'); chmod($path, 0600); });
    updateReject(fn() => $bad->stage($raw, $signature, 1700000000), 'bad payload rejected despite valid manifest signature');
    updateCheck(Files::readJson($temp . '/bad-download/highest-preview.json')['sequence'] === 1, 'interrupted install retains replay floor');
    updateCheck(count(iterator_to_array(new FilesystemIterator($temp . '/bad-download/releases'))) === 0, 'failed payload never becomes installed package');

    // Actual PHP process supervision verifies native updater argument/state orchestration.
    mkdir($temp . '/state', 0700); mkdir($temp . '/certs', 0700); mkdir($temp . '/native', 0700);
    $scanner = $temp . '/native/compact-scan'; $updater = $temp . '/native/compact-update';
    file_put_contents($scanner, "#!" . PHP_BINARY . "\n<?php echo json_encode(['protocol'=>1,'engine_sha256'=>str_repeat('a',64),'scan_options_sha256'=>str_repeat('b',64)]),\"\\n\";\n"); chmod($scanner, 0700);
    file_put_contents($updater, "#!" . PHP_BINARY . "\n<?php file_put_contents(getcwd().'/argv.json',json_encode(\$argv));chmod(getcwd().'/argv.json',0600);echo json_encode(['protocol'=>1,'status'=>'updated']),\"\\n\";\n"); chmod($updater, 0700);
    $settings = ['local_binary' => $scanner, 'updater_binary' => $updater, 'state_dir' => $temp . '/state', 'certificates_dir' => $temp . '/certs',
        'expected_current_sha256' => 'absent',
        'budgets' => ['native_as_bytes' => 200000000, 'native_process_bytes' => 300000000, 'native_account_available_bytes' => 300000000,
            'native_cpu_seconds' => 10, 'worker_wall_seconds' => 10, 'cleanup_seconds' => 1,
            'native_file_bytes' => 10000000, 'update_line_bytes' => 10000, 'update_reserve_bytes' => 100000,
            'freshclam_connect_seconds' => 10, 'freshclam_low_speed_seconds' => 10, 'freshclam_max_attempts' => 3, 'php_work_bytes' => 10000000,
            'storage_bytes' => 1000000, 'storage_reserve_bytes' => 1000, 'account_remaining_bytes' => 1000000,
            'inodes_remaining' => 1000, 'inode_reserve' => 5, 'update_state_inodes' => 100, 'quota_observed_at' => 1700000000,
            'cpu_hourly_seconds' => 100, 'cpu_daily_seconds' => 100, 'cpu_monthly_seconds' => 100]];
    $policy = new ResourcePolicy($settings);
    $policy->bindStore(new FileSubmissionStore($temp . '/cpu-ledger'));
    $policy->setOperationalPaths([$settings['state_dir']]);
    $unbound = $settings; unset($unbound['expected_current_sha256']);
    updateCheck((new NativeUpdateManager($unbound, $policy))->run()['status'] === 'not-configured', 'updater cannot inherit a program from an unbound current pointer');
    $manager = new NativeUpdateManager($settings, $policy);
    updateCheck($manager->run()['status'] === 'updated', 'official native updater invoked without shell or PHP DB buffering');
    $args = json_decode(file_get_contents($temp . '/state/argv.json'), true);
    updateCheck(in_array('--engine-sha256', $args, true) && in_array(str_repeat('a', 64), $args, true), 'exact runtime identity passed to generation builder');
    updateCheck(in_array('--as-bytes', $args, true) && in_array('--max-age-seconds', $args, true), 'resource/freshness policy passed explicitly');
    updateCheck(!in_array('--mirror', $args, true), 'no secondary database publisher dependency');
    updateCheck(in_array('--expected-current-sha256', $args, true) && in_array('absent', $args, true), 'native updater checks exact selected pointer under shared lock');
    updateCheck(in_array('--max-state-bytes', $args, true) && in_array('--max-state-inodes', $args, true), 'retained native generations and scratch have absolute ceilings');
    $reservePosition = array_search('--disk-reserve-bytes', $args, true);
    updateCheck($args[$reservePosition + 1] === '1000', 'filesystem operating reserve remains distinct from update workspace allocation');
    $cpu = $policy->diagnostics()['cpu_accounting'];
    updateCheck($cpu['pending_reservations'] === 0 && $cpu['windows']['hourly']['used_seconds'] > 0, 'preflight and update settle their CPU reservations');
    updateCheck($manager->run()['status'] === 'deferred', 'attempt spacing persists across calls');
    echo "Updates: $count checks passed\n";
    sodium_memzero($secret);
} finally { updateRemove($temp); }

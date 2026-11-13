<?php
declare(strict_types=1);
namespace WPClamAV\Updates;

use WPClamAV\Storage\Files;

/** Authenticated per-file installation, without archive extraction or activating mixed tuples. */
final class ReleaseInstaller
{
    private \Closure $download;
    public function __construct(private string $root, private string $publicKey, private string $channel,
        private int $stagingBudget, private int $timeoutSeconds, ?callable $download = null)
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) { throw new \RuntimeException('Native code updates require PHP sodium.'); }
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !in_array($channel, ['stable', 'preview'], true)) { throw new \InvalidArgumentException('Configure a channel-specific release trust key.'); }
        if ($stagingBudget < 1 || $timeoutSeconds < 1) { throw new \InvalidArgumentException('Configure code-update disk and time allocations.'); }
        Files::privateDirectory($root);
        foreach (['releases', 'staging'] as $part) {
            if (!file_exists($root . '/' . $part)) { mkdir($root . '/' . $part, 0700); }
            Files::privateDirectory($root . '/' . $part);
        }
        $this->download = $download ? \Closure::fromCallable($download) : $this->downloadFile(...);
    }

    public function stage(string $manifestBytes, string $signature, ?int $now = null): array
    {
        $now ??= time();
        if (strlen($manifestBytes) > 1048576 || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $manifestBytes, $this->publicKey)) { throw new \RuntimeException('Release signature is invalid. No downloaded program was executed.'); }
        $manifest = json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['protocol'] ?? null) !== 1 || ($manifest['channel'] ?? null) !== $this->channel
            || !is_int($manifest['sequence'] ?? null) || $manifest['sequence'] < 1 || !is_int($manifest['expires_at'] ?? null) || $manifest['expires_at'] <= $now) {
            throw new \RuntimeException('Release is expired or incompatible with the selected trust channel.');
        }
        if (!is_string($manifest['version'] ?? null) || !preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?\z/D', $manifest['version'])) { throw new \RuntimeException('Release version is invalid.'); }
        if (PHP_OS_FAMILY !== 'Linux' || php_uname('m') !== 'x86_64' || !in_array($manifest['platform'] ?? null, ['linux-x86_64-glibc2.36', 'linux-x86_64-glibc-2.36'], true)) {
            throw new \RuntimeException('This native package does not match the host platform.');
        }
        $hash = hash('sha256', $manifestBytes); $id = 'pkg-' . $hash;
        $seenPath = $this->root . '/highest-' . $this->channel . '.json';
        $seen = file_exists($seenPath) ? Files::readJson($seenPath) : ['sequence' => 0, 'manifest_sha256' => null];
        if ($manifest['sequence'] < $seen['sequence'] || ($manifest['sequence'] === $seen['sequence'] && $seen['manifest_sha256'] !== $hash)) {
            throw new \RuntimeException('Release replay or replacement of an accepted sequence was rejected.');
        }
        $files = $manifest['files'] ?? null;
        if (!is_array($files) || !array_is_list($files) || count($files) < 2 || count($files) > 512) { throw new \RuntimeException('Release file inventory is invalid.'); }
        $total = 0; $paths = [];
        foreach ($files as $file) {
            $path = $file['path'] ?? '';
            if (!is_string($path) || !preg_match('~\A(?:bin|lib|certs|notices)/[A-Za-z0-9_./+-]+\z~D', $path)
                || str_contains($path, '..') || str_contains($path, '//') || str_ends_with($path, '/') || isset($paths[$path])) { throw new \RuntimeException('Unsafe or duplicate release path.'); }
            if (!is_int($file['size'] ?? null) || $file['size'] < 1 || !preg_match('/\A[a-f0-9]{64}\z/D', $file['sha256'] ?? '')) { throw new \RuntimeException('Release file identity is invalid.'); }
            if (!in_array($file['mode'] ?? null, [0400, 0500, 0600, 0700, '0400', '0500', '0600', '0700'], true)) { throw new \RuntimeException('Unsafe native file mode.'); }
            $url = $file['url'] ?? '';
            if (!is_string($url) || !str_starts_with($url, 'https://github.com/Barbarrosa/clamav/releases/download/')) { throw new \RuntimeException('Native releases must originate from the configured ClamAV repository.'); }
            $total += $file['size'];
            if ($total > $this->stagingBudget || $total < 0) { throw new \RuntimeException('Native release exceeds the calculated staging allocation.'); }
            $paths[$path] = true;
        }
        foreach (['bin/compact-scan', 'bin/compact-update'] as $required) { if (!isset($paths[$required])) { throw new \RuntimeException('Native release is missing a required component.'); } }
        // Persist the replay floor before fallible I/O, including interrupted downloads.
        Files::json($seenPath, ['sequence' => $manifest['sequence'], 'manifest_sha256' => $hash, 'accepted_at' => $now]);
        $destination = $this->root . '/releases/' . $id;
        if (is_dir($destination) && !is_link($destination)) {
            $stored = file_get_contents($destination . '/manifest.exact');
            if ($stored !== $manifestBytes) { throw new \RuntimeException('Existing native package identity changed.'); }
            foreach ($files as $file) {
                $path = $destination . '/' . $file['path']; Files::regular($path);
                if (filesize($path) !== $file['size'] || !hash_equals($file['sha256'], hash_file('sha256', $path))) { throw new \RuntimeException('Installed package changed.'); }
            }
            return ['id' => $id, 'path' => $destination, 'manifest' => $manifest];
        }
        $available = disk_free_space($this->root);
        if ($available === false || $available < $total * 1.20) { throw new \RuntimeException('Insufficient space to stage and verify the native code release.'); }
        $stage = $this->root . '/staging/' . bin2hex(random_bytes(16));
        if (!mkdir($stage, 0700)) { throw new \RuntimeException('Cannot create native staging directory.'); }
        $deadline = microtime(true) + $this->timeoutSeconds;
        try {
            foreach ($files as $file) {
                if (microtime(true) >= $deadline) { throw new \RuntimeException('Code update time allocation exhausted.'); }
                $path = $stage . '/' . $file['path'];
                if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) { throw new \RuntimeException('Cannot create native package directory.'); }
                ($this->download)($file['url'], $path, $file['size'], max(1, (int) ($deadline - microtime(true))));
                Files::regular($path);
                if (filesize($path) !== $file['size'] || !hash_equals($file['sha256'], hash_file('sha256', $path))) { throw new \RuntimeException('Downloaded native code does not match its signed identity.'); }
                $mode = is_int($file['mode']) ? $file['mode'] : octdec($file['mode']);
                if (str_starts_with($file['path'], 'bin/') || in_array($file['path'], ['lib/compact-scan.real', 'lib/compact-update.real'], true)) { $mode = 0500; } else { $mode = 0400; }
                if (!chmod($path, $mode)) { throw new \RuntimeException('Could not protect native package.'); }
            }
            Files::json($stage . '/manifest.json', $manifest, true);
            // Keep exact signed bytes, rather than depending on JSON reserialization.
            $exact = fopen($stage . '/manifest.exact', 'xb'); chmod($stage . '/manifest.exact', 0400);
            Files::writeAll($exact, $manifestBytes); Files::sync($exact); fclose($exact);
            Files::syncDirectory($stage);
            if (!rename($stage, $destination)) { throw new \RuntimeException('Could not commit native package.'); }
            Files::syncDirectory($this->root . '/releases');
            return ['id' => $id, 'path' => $destination, 'manifest' => $manifest];
        } catch (\Throwable $error) {
            // Staged program artifacts contain no submission originals and may be discarded.
            self::removeStage($stage);
            throw $error;
        }
    }

    private static function removeStage(string $path): void
    {
        if (!is_dir($path) || is_link($path)) { return; }
        foreach (new \DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) { continue; }
            if ($entry->isDir() && !$entry->isLink()) { self::removeStage($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($path);
    }

    private function downloadFile(string $url, string $path, int $expected, int $timeout): void
    {
        if (!function_exists('curl_init')) { throw new \RuntimeException('Streaming code installation requires PHP cURL.'); }
        $old = umask(0077); try { $output = fopen($path, 'xb'); } finally { umask($old); }
        if ($output === false) { throw new \RuntimeException('Could not create staged native file.'); }
        $handle = curl_init($url); $count = 0;
        curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min($timeout, 10), CURLOPT_USERAGENT => 'WP-ClamAV/0.1',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($output, $expected, &$count): int {
                if ($count + strlen($chunk) > $expected) { return 0; }
                try { Files::writeAll($output, $chunk); } catch (\Throwable) { return 0; }
                $count += strlen($chunk); return strlen($chunk);
            }]);
        try {
            if (curl_exec($handle) === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200 || $count !== $expected) { throw new \RuntimeException('Native download did not complete correctly.'); }
            Files::sync($output);
        } finally { curl_close($handle); fclose($output); }
    }
}

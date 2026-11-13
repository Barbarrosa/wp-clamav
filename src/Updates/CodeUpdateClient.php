<?php
declare(strict_types=1);
namespace WPClamAV\Updates;

use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Storage\Files;

/** Fetches authenticated candidates from one repository; activation remains a native operation. */
final class CodeUpdateClient
{
    private const REPOSITORY = 'Barbarrosa/clamav';
    private const API = 'https://api.github.com/repos/Barbarrosa/clamav/releases';
    private const DOWNLOAD = 'https://github.com/Barbarrosa/clamav/releases/download/';
    private const PLATFORM = 'linux-x86_64-glibc2.36';
    private \Closure $http;
    private ?\Closure $download;
    private float $deadline = 0;
    private ?string $schedule = null;

    /** Optional transports accept WordPress HTTP arguments and ReleaseInstaller's per-file arguments. */
    public function __construct(private array $settings, private ResourcePolicy $policy, private string $programsRoot,
        ?callable $http = null, ?callable $download = null)
    {
        $this->http = $http ? \Closure::fromCallable($http) : static function (string $url, array $arguments): mixed {
            if (!function_exists('wp_remote_get')) { throw new \RuntimeException('Native release discovery requires the WordPress HTTP API.'); }
            return wp_remote_get($url, $arguments);
        };
        $this->download = $download ? \Closure::fromCallable($download) : null;
    }

    public function stageNext(): ?array
    {
        $configured = $this->settings['native_release_public_key'] ?? '';
        // Not configuring code trust must not disable independently configured FreshClam updates.
        if ($configured === '' || $configured === null) { return null; }
        if (!is_string($configured) || ($key = base64_decode($configured, true)) === false
            || strlen($key) !== 32 || base64_encode($key) !== $configured) {
            throw new \InvalidArgumentException('Configure a canonical base64 Ed25519 native release public key.');
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) { throw new \RuntimeException('Native release authentication requires PHP sodium.'); }
        $channel = $this->settings['release_channel'] ?? 'stable';
        if ($channel === '') { $channel = 'stable'; }
        if (!in_array($channel, ['stable', 'preview'], true)) { throw new \InvalidArgumentException('Native releases require stable or explicitly opted-in preview channel.'); }
        $staging = $this->allocation('code_staging_bytes');
        $seconds = $this->allocation('code_update_seconds');
        $this->allocation('account_remaining_bytes');
        $limits = $this->policy->remoteLimits(PHP_SAPI !== 'cli');
        $seconds = min($seconds, (float) $limits['wall_seconds']);
        if ($seconds < 1) { throw new \RuntimeException('Insufficient time remains for a native code update.'); }
        $this->deadline = microtime(true) + $seconds;
        $metadataLimit = min(1048576, (int) $limits['output_bytes']);
        if ($metadataLimit < 4096) { throw new \RuntimeException('Insufficient PHP headroom for bounded release metadata.'); }
        $this->prepareRoot();
        $lockPath = $this->programsRoot . '/code-update.lock';
        if (file_exists($lockPath) || is_link($lockPath)) { Files::regular($lockPath); }
        $old = umask(0077);
        try { $lock = @fopen($lockPath, 'c+b'); } finally { umask($old); }
        if ($lock === false) { throw new \RuntimeException('Cannot open native code-update lock.'); }
        try {
            $stat = Files::regular($lockPath); $opened = fstat($lock);
            if ($opened === false || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev']) {
                throw new \RuntimeException('Native code-update lock changed while opening.');
            }
            if (!flock($lock, LOCK_EX | LOCK_NB)) { return null; }
            try {
                $this->schedule = $this->programsRoot . '/code-update-' . $channel . '.json';
                $now = time();
                $previous = file_exists($this->schedule) ? Files::readJson($this->schedule) : [];
                if (isset($previous['next_at']) && (!is_int($previous['next_at']) || $previous['next_at'] < 0)) {
                    throw new \RuntimeException('Invalid native update schedule.');
                }
                if ($now < ($previous['next_at'] ?? 0)) { return null; }
                $interval = max(7200, $this->optionalInterval('provider_cron_interval_seconds', 1800),
                    $this->optionalInterval('update_interval_seconds'), $this->optionalInterval('code_check_interval_seconds'));
                // Record unsuccessful attempts too, preventing a busy cron from hammering GitHub.
                Files::json($this->schedule, ['channel' => $channel, 'attempted_at' => $now, 'next_at' => $now + $interval]);
                $release = $this->release($channel, $metadataLimit);
                if ($release === null) { return null; }
                $tag = $release['tag_name'];
                [$manifestAsset, $signatureAsset] = $this->assets($release, $tag, $metadataLimit);
                $bytes = $this->body($manifestAsset['browser_download_url'], $metadataLimit, $manifestAsset['size']);
                $signature = $this->body($signatureAsset['browser_download_url'], 65, 64);
                if (!sodium_crypto_sign_verify_detached($signature, $bytes, $key)) {
                    throw new \RuntimeException('Native release signature is invalid; no downloaded program was executed.');
                }
                $manifest = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
                $required = $this->bindManifest($manifest, $channel, $tag);
                // The installer retains both exact JSON and its decoded audit record.
                $metadataBytes = strlen($bytes) * 2 + 8192;
                if ($required > PHP_INT_MAX - $metadataBytes) { throw new \RuntimeException('Native staging size overflows this platform.'); }
                $required += $metadataBytes;
                if ($required + $this->policy->margin($required) > $staging) {
                    throw new \RuntimeException('Native release exceeds the confirmed code staging allocation and headroom.');
                }
                $usage = $this->retainedUsage();
                $available = $this->policy->storageHeadroom($this->programsRoot, $usage)['available_bytes'];
                if ($required + $this->policy->margin($required) > $available) {
                    throw new \RuntimeException('Native release cannot fit the confirmed account capacity and retained-data reserves.');
                }
                $installer = new ReleaseInstaller($this->programsRoot, $key, $channel, (int) $staging,
                    $this->remaining(), $this->download);
                return $installer->stage($bytes, $signature, time());
            } finally { flock($lock, LOCK_UN); }
        } finally { fclose($lock); }
    }

    private function allocation(string $name): float
    {
        $value = $this->settings['budgets'][$name] ?? null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 1 || $value >= PHP_INT_MAX) {
            throw new \InvalidArgumentException('Confirm native code-update allocation: ' . $name);
        }
        return (float) $value;
    }

    private function optionalInterval(string $name, int $default = 0): int
    {
        $value = $this->settings['budgets'][$name] ?? $default;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 31536000) {
            throw new \InvalidArgumentException('Invalid code-update interval: ' . $name);
        }
        return (int) ceil($value);
    }

    private function prepareRoot(): void
    {
        $root = rtrim($this->programsRoot, '/');
        if ($root === '' || $root[0] !== '/' || preg_match('/[\x00-\x1f\x7f]/', $root) || is_link($root)) {
            throw new \InvalidArgumentException('Native programs require an absolute private directory.');
        }
        $parent = realpath(dirname($root));
        if ($parent === false) { throw new \RuntimeException('Native program parent directory does not exist.'); }
        $root = $parent . '/' . basename($root);
        foreach ([defined('ABSPATH') ? ABSPATH : null, $_SERVER['DOCUMENT_ROOT'] ?? null] as $documentRoot) {
            if (!is_string($documentRoot) || ($public = realpath($documentRoot)) === false) { continue; }
            if ($root === $public || str_starts_with($root . '/', rtrim($public, '/') . '/')) {
                throw new \RuntimeException('Native programs must be outside every document root.');
            }
        }
        if (!file_exists($root) && !Files::track($root, static fn(): bool => mkdir($root, 0700))) {
            throw new \RuntimeException('Could not create native program directory.');
        }
        Files::privateDirectory($root);
        $this->programsRoot = $root;
    }

    private function release(string $channel, int $limit): ?array
    {
        $value = null;
        if ($channel === 'preview') {
            // Each native release has about 100 individual assets. A 100-release
            // response can exceed the protocol buffer after only a few versions.
            // Small bounded pages retain the same overall work deadline.
            for ($page = 1; $page <= 20; ++$page) {
                $response = $this->get(self::API . '?per_page=3&page=' . $page, $limit);
                if ($response['status'] === 404) { return null; }
                $this->requireSuccess($response);
                $entries = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($entries) || !array_is_list($entries) || count($entries) > 3) { throw new \RuntimeException('Invalid preview release inventory.'); }
                foreach ($entries as $entry) {
                    if (is_array($entry) && ($entry['draft'] ?? null) === false && ($entry['prerelease'] ?? null) === true) { $value = $entry; break 2; }
                }
                if (count($entries) < 3) { return null; }
            }
            if ($value === null) { return null; }
        } else {
            $response = $this->get(self::API . '/latest', $limit);
            if ($response['status'] === 404) { return null; }
            $this->requireSuccess($response);
            $value = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        }
        if (!is_array($value) || ($value['draft'] ?? null) !== false || ($value['prerelease'] ?? null) !== ($channel === 'preview')
            || !is_string($value['tag_name'] ?? null) || !preg_match('/\Acompact-v[0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?\z/D', $value['tag_name'])) {
            throw new \RuntimeException('GitHub release does not match the selected native release channel.');
        }
        return $value;
    }

    private function assets(array $release, string $tag, int $manifestLimit): array
    {
        if (!is_array($release['assets'] ?? null) || count($release['assets']) > 1024) { throw new \RuntimeException('Invalid native release assets.'); }
        $found = [];
        foreach ($release['assets'] as $asset) {
            if (!is_array($asset) || !in_array($asset['name'] ?? null, ['manifest.json', 'manifest.sig'], true)) { continue; }
            $name = $asset['name'];
            if (isset($found[$name]) || ($asset['state'] ?? null) !== 'uploaded'
                || !is_int($asset['size'] ?? null) || $asset['size'] < 1
                || ($asset['browser_download_url'] ?? null) !== self::DOWNLOAD . $tag . '/' . $name) {
                throw new \RuntimeException('Native manifest assets are missing, ambiguous or outside the fixed release.');
            }
            $found[$name] = $asset;
        }
        if (!isset($found['manifest.json'], $found['manifest.sig']) || $found['manifest.json']['size'] > $manifestLimit
            || $found['manifest.sig']['size'] !== 64) { throw new \RuntimeException('Native manifest or signature exceeds its protocol bound.'); }
        return [$found['manifest.json'], $found['manifest.sig']];
    }

    private function bindManifest(mixed $manifest, string $channel, string $tag): int
    {
        if (!is_array($manifest) || ($manifest['repository'] ?? null) !== self::REPOSITORY || ($manifest['tag'] ?? null) !== $tag
            || ($manifest['platform'] ?? null) !== self::PLATFORM || ($manifest['channel'] ?? null) !== $channel
            || !is_string($manifest['version'] ?? null) || 'compact-v' . $manifest['version'] !== $tag
            || !is_array($manifest['files'] ?? null) || !array_is_list($manifest['files']) || count($manifest['files']) > 512) {
            throw new \RuntimeException('Signed native manifest does not match the repository, release tag, platform or channel.');
        }
        $total = 0;
        foreach ($manifest['files'] as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null) || !is_string($file['sha256'] ?? null)
                || !preg_match('/\A[a-f0-9]{64}\z/D', $file['sha256']) || !is_int($file['size'] ?? null) || $file['size'] < 1
                || $file['size'] > PHP_INT_MAX - $total
                || ($file['url'] ?? null) !== self::DOWNLOAD . $tag . '/' . substr($file['sha256'], 0, 16) . '--' . basename($file['path'])) {
                throw new \RuntimeException('Signed native file is not bound to its exact release asset.');
            }
            $total += $file['size'];
        }
        return $total;
    }

    private function retainedUsage(): array
    {
        $root = $this->settings['retained_storage_root'] ?? $this->settings['storage_path'] ?? null;
        if (!is_string($root) || $root === '') { throw new \RuntimeException('Retained-storage accounting is required before code staging.'); }
        Files::privateDirectory($root);
        if (file_exists($root . '/.usage-dirty.json') || file_exists($root . '/.usage-reconcile.json')) {
            throw new \RuntimeException('Reconcile retained-storage accounting before code staging.');
        }
        $usage = $this->settings['retained_storage_usage'] ?? Files::readJson($root . '/usage.json');
        if (!is_array($usage) || ($usage['schema'] ?? null) !== 2 || ($usage['complete'] ?? null) !== true
            || !is_int($usage['bytes'] ?? null) || $usage['bytes'] < 0) {
            throw new \RuntimeException('Confirmed retained-storage accounting is unavailable.');
        }
        return $usage;
    }

    private function remaining(): int
    {
        $remaining = (int) floor($this->deadline - microtime(true));
        if ($remaining < 1) { throw new \RuntimeException('Native code-update time allocation exhausted.'); }
        return $remaining;
    }

    private function body(string $url, int $limit, int $expected): string
    {
        $response = $this->get($url, $limit);
        $this->requireSuccess($response);
        if (strlen($response['body']) !== $expected) { throw new \RuntimeException('Native release metadata was truncated or changed.'); }
        return $response['body'];
    }

    private function get(string $url, int $limit): array
    {
        for ($redirects = 0; ; ++$redirects) {
            $this->safeUrl($url);
            $raw = ($this->http)($url, ['timeout' => $this->remaining(), 'redirection' => 0, 'sslverify' => true,
                'reject_unsafe_urls' => true, 'limit_response_size' => $limit, 'cookies' => [],
                'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'WP-ClamAV/0.1']]);
            if (!is_array($raw) || !is_int($raw['response']['code'] ?? null) || !is_string($raw['body'] ?? null)) {
                throw new \RuntimeException('Native release HTTP request failed.');
            }
            if (strlen($raw['body']) > $limit) { throw new \RuntimeException('Native release HTTP response exceeded its allocation.'); }
            $headers = $raw['headers'] ?? [];
            $header = static function (string $name) use ($headers): string {
                $value = (is_array($headers) || $headers instanceof \ArrayAccess) ? ($headers[$name] ?? '') : '';
                return is_string($value) ? $value : '';
            };
            $status = $raw['response']['code'];
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($redirects >= 3 || ($url = $header('location')) === '') { throw new \RuntimeException('Native release redirect limit exceeded.'); }
                continue;
            }
            $length = $header('content-length');
            if ($length !== '' && (!ctype_digit($length) || (float) $length > $limit)) { throw new \RuntimeException('Native release HTTP length exceeds its allocation.'); }
            if (in_array($status, [403, 429, 503], true) && $this->schedule !== null) {
                $retry = $header('retry-after');
                $until = ctype_digit($retry) ? time() + min(31536000, (int) $retry) : ($retry !== '' ? strtotime($retry) : false);
                $reset = $header('x-ratelimit-reset');
                if (ctype_digit($reset)) { $until = max((int) $until, min(time() + 31536000, (int) $reset)); }
                if ($until !== false) {
                    $schedule = Files::readJson($this->schedule);
                    $schedule['next_at'] = max($schedule['next_at'], (int) $until);
                    Files::json($this->schedule, $schedule);
                }
            }
            return ['status' => $status, 'body' => $raw['body']];
        }
    }

    private function safeUrl(string $url): void
    {
        $parsed = parse_url($url);
        if (!is_array($parsed) || ($parsed['scheme'] ?? null) !== 'https'
            || !in_array($parsed['host'] ?? '', ['api.github.com', 'github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true)
            || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['fragment'])
            || isset($parsed['port']) && $parsed['port'] !== 443 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new \RuntimeException('Native release redirect is outside the approved HTTPS origins.');
        }
    }

    private function requireSuccess(array $response): void
    {
        if ($response['status'] !== 200) { throw new \RuntimeException('Native release server returned HTTP ' . $response['status'] . '.'); }
    }
}

<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'WPClamAV\\')) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});

use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Storage\Files;
use WPClamAV\Updates\CodeUpdateClient;

$checks = 0;
function codeClientCheck(bool $condition, string $message): void
{
    global $checks; ++$checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function codeClientRejects(callable $operation, string $message): Throwable
{
    try { $operation(); } catch (Throwable $error) { codeClientCheck(true, $message); return $error; }
    throw new RuntimeException('FAIL: ' . $message);
}
function codeClientRemove(string $path): void
{
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (new DirectoryIterator($path) as $entry) { if (!$entry->isDot()) { codeClientRemove($entry->getPathname()); } }
    rmdir($path);
}

/** No fixture makes a network request or runs the staged program. */
final class CodeClientFixture
{
    public array $settings;
    public array $manifest;
    public array $httpCalls = [];
    public array $downloads = [];
    public array $contents = [];
    public ?Closure $response = null;
    public ?array $previewList = null;
    public string $signature;
    public string $manifestBytes;
    private string $secret;
    public string $programs;

    public function __construct(public string $directory, string $channel = 'stable')
    {
        mkdir($directory, 0700);
        mkdir($directory . '/private', 0700);
        $this->programs = $directory . '/private/programs';
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->settings = ['native_release_public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'release_channel' => $channel, 'storage_path' => $directory . '/private',
            'retained_storage_usage' => ['schema' => 2, 'complete' => true, 'bytes' => 4096],
            'budgets' => ['code_staging_bytes' => 2000000, 'code_update_seconds' => 10,
                'storage_bytes' => 20000000, 'storage_reserve_bytes' => 100000, 'update_reserve_bytes' => 100000,
                'account_remaining_bytes' => 10000000, 'worker_wall_seconds' => 15, 'request_work_seconds' => 15,
                'cleanup_seconds' => 1, 'php_work_bytes' => 64000000]];
        $files = [];
        foreach (['bin/compact-scan' => "do not execute scanner\n", 'bin/compact-update' => "do not execute updater\n"] as $path => $bytes) {
            $hash = hash('sha256', $bytes);
            $url = 'https://github.com/Barbarrosa/clamav/releases/download/compact-v0.1.0/' . substr($hash, 0, 16) . '--' . basename($path);
            $files[] = ['path' => $path, 'size' => strlen($bytes), 'sha256' => $hash, 'mode' => '0500', 'url' => $url];
            $this->contents[$url] = $bytes;
        }
        $this->manifest = ['protocol' => 1, 'repository' => 'Barbarrosa/clamav', 'tag' => 'compact-v0.1.0',
            'channel' => $channel, 'version' => '0.1.0', 'platform' => 'linux-x86_64-glibc2.36',
            'sequence' => 1, 'expires_at' => time() + 86400, 'files' => $files];
        $this->sign();
    }

    public function sign(): void
    {
        // Whitespace is deliberate: authenticity must cover exact bytes, not reserialized JSON.
        $this->manifestBytes = json_encode($this->manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $this->signature = sodium_crypto_sign_detached($this->manifestBytes, $this->secret);
    }

    public function release(?string $channel = null): array
    {
        $tag = 'compact-v0.1.0';
        $base = 'https://github.com/Barbarrosa/clamav/releases/download/' . $tag . '/';
        return ['tag_name' => $tag, 'draft' => false, 'prerelease' => ($channel ?? $this->settings['release_channel']) === 'preview',
            'assets' => [
                ['name' => 'manifest.json', 'state' => 'uploaded', 'size' => strlen($this->manifestBytes), 'browser_download_url' => $base . 'manifest.json'],
                ['name' => 'manifest.sig', 'state' => 'uploaded', 'size' => 64, 'browser_download_url' => $base . 'manifest.sig'],
            ]];
    }

    public function reply(string $url, array $arguments): array
    {
        $this->httpCalls[] = [$url, $arguments];
        codeClientCheck(($arguments['redirection'] ?? null) === 0 && ($arguments['sslverify'] ?? null) === true
            && ($arguments['reject_unsafe_urls'] ?? null) === true, 'HTTP disables implicit redirects and verifies TLS/addresses');
        codeClientCheck(is_int($arguments['limit_response_size'] ?? null) && $arguments['limit_response_size'] <= 1048576
            && $arguments['timeout'] > 0 && $arguments['timeout'] <= 10, 'each HTTP request has bounded bytes and remaining time');
        codeClientCheck(!isset($arguments['headers']['Authorization']) && $arguments['cookies'] === [], 'discovery forwards no account credentials');
        if ($this->response !== null && ($response = ($this->response)($url, $arguments, $this)) !== null) { return $response; }
        $body = match ($url) {
            'https://api.github.com/repos/Barbarrosa/clamav/releases/latest' => json_encode($this->release(), JSON_THROW_ON_ERROR),
            'https://api.github.com/repos/Barbarrosa/clamav/releases?per_page=3&page=1' => json_encode($this->previewList ?? [$this->release()], JSON_THROW_ON_ERROR),
            'https://github.com/Barbarrosa/clamav/releases/download/compact-v0.1.0/manifest.json' => $this->manifestBytes,
            'https://github.com/Barbarrosa/clamav/releases/download/compact-v0.1.0/manifest.sig' => $this->signature,
            default => throw new RuntimeException('Unexpected fixture HTTP URL: ' . $url),
        };
        return ['response' => ['code' => 200], 'headers' => [], 'body' => $body];
    }

    public function client(?array $settings = null): CodeUpdateClient
    {
        $settings ??= $this->settings;
        $policy = new ResourcePolicy($settings, ['memory_limit' => ['value' => '256M'], 'php_usage' => ['value' => 0]]);
        return new CodeUpdateClient($settings, $policy, $this->programs, $this->reply(...), function (string $url, string $path, int $expected, int $timeout): void {
            $this->downloads[] = $url;
            codeClientCheck(isset($this->contents[$url]) && strlen($this->contents[$url]) === $expected && $timeout > 0, 'installer downloads only declared bounded files');
            file_put_contents($path, $this->contents[$url]);
            chmod($path, 0600);
        });
    }

    public function schedule(): string { return $this->programs . '/code-update-' . $this->settings['release_channel'] . '.json'; }
    public function allowNextAttempt(): void
    {
        $record = Files::readJson($this->schedule()); $record['next_at'] = 0; Files::json($this->schedule(), $record);
    }
}

$temporary = sys_get_temp_dir() . '/wp-clamav-code-client-' . bin2hex(random_bytes(16));
mkdir($temporary, 0700);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    $fixture = new CodeClientFixture($temporary . '/disabled');
    $disabled = $fixture->settings; unset($disabled['native_release_public_key']);
    $disabled['release_channel'] = 'invalid'; $disabled['budgets'] = [];
    codeClientCheck($fixture->client($disabled)->stageNext() === null && $fixture->httpCalls === []
        && !file_exists($fixture->programs), 'absent trust key skips code updates without touching disk or HTTP');

    foreach (['not base64', base64_encode('short'), $fixture->settings['native_release_public_key'] . "\n"] as $key) {
        $invalid = $fixture->settings; $invalid['native_release_public_key'] = $key;
        codeClientRejects(fn() => $fixture->client($invalid)->stageNext(), 'invalid/noncanonical trust keys fail before discovery');
    }
    foreach (['code_staging_bytes', 'code_update_seconds', 'account_remaining_bytes'] as $allocation) {
        $invalid = $fixture->settings; unset($invalid['budgets'][$allocation]);
        codeClientRejects(fn() => $fixture->client($invalid)->stageNext(), 'missing confirmed ' . $allocation . ' cannot be guessed');
    }
    $invalid = $fixture->settings; $invalid['release_channel'] = 'nightly';
    codeClientRejects(fn() => $fixture->client($invalid)->stageNext(), 'unknown channel does not silently become stable');

    $fixture = new CodeClientFixture($temporary . '/empty-channel');
    $settings = $fixture->settings; $settings['release_channel'] = '';
    codeClientCheck($fixture->client($settings)->stageNext()['manifest']['channel'] === 'stable', 'unset legacy channel uses stable rather than opting into preview');

    $fixture = new CodeClientFixture($temporary . '/stable');
    unset($fixture->settings['release_channel']);
    // Test helpers use the effective default too.
    $fixture->settings['release_channel'] = 'stable';
    $settings = $fixture->settings; unset($settings['release_channel']);
    $candidate = $fixture->client($settings)->stageNext();
    codeClientCheck(is_array($candidate) && $candidate['manifest'] === $fixture->manifest, 'default stable channel stages the signed candidate');
    codeClientCheck(file_get_contents($candidate['path'] . '/manifest.exact') === $fixture->manifestBytes, 'exact signed JSON bytes are retained');
    codeClientCheck(file_get_contents($candidate['path'] . '/bin/compact-scan') === "do not execute scanner\n"
        && (fileperms($candidate['path'] . '/bin/compact-scan') & 0777) === 0500, 'declared program bytes are verified and protected');
    codeClientCheck(!file_exists($fixture->programs . '/current.json'), 'staging never selects an active program/database tuple');
    $schedule = Files::readJson($fixture->schedule());
    codeClientCheck($schedule['next_at'] - $schedule['attempted_at'] === 7200, 'default code discovery uses the FreshClam-aligned two-hour floor');
    $calls = count($fixture->httpCalls);
    codeClientCheck($fixture->client()->stageNext() === null && count($fixture->httpCalls) === $calls, 'successful discovery is durably spaced');
    $fixture->allowNextAttempt();
    $downloads = count($fixture->downloads);
    codeClientCheck($fixture->client()->stageNext()['id'] === $candidate['id'] && count($fixture->downloads) === $downloads,
        'a later retry reuses a verified staged package without downloading or activating it');

    $fixture = new CodeClientFixture($temporary . '/preview', 'preview');
    $draft = $fixture->release(); $draft['draft'] = true;
    $stable = $fixture->release('stable');
    $fixture->previewList = [$draft, $stable, $fixture->release()];
    codeClientCheck($fixture->client()->stageNext()['manifest']['channel'] === 'preview', 'preview explicitly selects the first non-draft prerelease');
    codeClientCheck(str_ends_with($fixture->httpCalls[0][0], '?per_page=3&page=1'), 'preview inventory uses small pages for native releases with many assets');

    $fixture = new CodeClientFixture($temporary . '/preview-page-two', 'preview');
    $fixture->previewList = array_fill(0, 3, $fixture->release('stable'));
    $fixture->response = static fn($url, $args, $self) => str_ends_with($url, '?per_page=3&page=2')
        ? ['response' => ['code' => 200], 'headers' => [], 'body' => json_encode([$self->release()], JSON_THROW_ON_ERROR)] : null;
    codeClientCheck($fixture->client()->stageNext()['manifest']['channel'] === 'preview', 'bounded preview paging finds a prerelease beyond the first asset-heavy page');

    $fixture = new CodeClientFixture($temporary . '/no-release');
    $fixture->response = static fn() => ['response' => ['code' => 404], 'headers' => [], 'body' => '{}'];
    codeClientCheck($fixture->client()->stageNext() === null && $fixture->downloads === [], 'no published release leaves current native code untouched');

    $fixture = new CodeClientFixture($temporary . '/no-preview', 'preview');
    $fixture->previewList = [$fixture->release('stable')];
    codeClientCheck($fixture->client()->stageNext() === null, 'preview never substitutes a stable release when no prerelease exists');

    $fixture = new CodeClientFixture($temporary . '/longer-interval');
    $fixture->settings['budgets']['provider_cron_interval_seconds'] = 10800;
    $fixture->client()->stageNext();
    $schedule = Files::readJson($fixture->schedule());
    codeClientCheck($schedule['next_at'] - $schedule['attempted_at'] === 10800, 'provider interval can lengthen the shared check floor');

    $fixture = new CodeClientFixture($temporary . '/failed-http');
    $fixture->response = static fn() => ['response' => ['code' => 503], 'headers' => ['retry-after' => '36000'], 'body' => 'busy'];
    codeClientRejects(fn() => $fixture->client()->stageNext(), 'configured HTTP failure is reported to the caller');
    $schedule = Files::readJson($fixture->schedule());
    codeClientCheck($schedule['next_at'] >= time() + 35998, 'provider retry-after can lengthen the persisted interval');
    $calls = count($fixture->httpCalls);
    codeClientCheck($fixture->client()->stageNext() === null && count($fixture->httpCalls) === $calls, 'failed attempts cannot be retried by every cron tick');

    foreach (['repository', 'tag', 'platform', 'channel', 'file-url'] as $fault) {
        $fixture = new CodeClientFixture($temporary . '/binding-' . $fault);
        if ($fault === 'file-url') { $fixture->manifest['files'][0]['url'] = 'https://github.com/other/project/releases/download/compact-v0.1.0/scanner'; }
        else { $fixture->manifest[$fault] = 'unexpected'; }
        $fixture->sign();
        codeClientRejects(fn() => $fixture->client()->stageNext(), 'even signed ' . $fault . ' cannot escape the fixed release contract');
        codeClientCheck($fixture->downloads === [], 'invalid binding never downloads program files');
    }

    $fixture = new CodeClientFixture($temporary . '/bad-signature');
    $fixture->signature[0] = chr(ord($fixture->signature[0]) ^ 1);
    codeClientRejects(fn() => $fixture->client()->stageNext(), 'signature mismatch cannot authorize code staging');
    codeClientCheck($fixture->downloads === [], 'unsigned program data is never fetched');

    $fixture = new CodeClientFixture($temporary . '/expired');
    $fixture->manifest['expires_at'] = time() - 1; $fixture->sign();
    codeClientRejects(fn() => $fixture->client()->stageNext(), 'expired signed manifests cannot stage executable files');
    codeClientCheck($fixture->downloads === [], 'expired release stops before per-file downloads');

    $fixture = new CodeClientFixture($temporary . '/missing-asset');
    $fixture->response = static function (string $url, array $arguments, CodeClientFixture $fixture): ?array {
        if (!str_ends_with($url, '/latest')) { return null; }
        $release = $fixture->release(); unset($release['assets'][0]['browser_download_url']);
        return ['response' => ['code' => 200], 'headers' => [], 'body' => json_encode($release, JSON_THROW_ON_ERROR)];
    };
    codeClientRejects(fn() => $fixture->client()->stageNext(), 'missing asset URLs reject cleanly without PHP notices');

    $fixture = new CodeClientFixture($temporary . '/truncated');
    $fixture->response = static function (string $url, array $arguments, CodeClientFixture $fixture): ?array {
        return str_ends_with($url, '/manifest.json') ? ['response' => ['code' => 200], 'headers' => [], 'body' => substr($fixture->manifestBytes, 0, -1)] : null;
    };
    codeClientRejects(fn() => $fixture->client()->stageNext(), 'truncated exact metadata is rejected');

    $fixture = new CodeClientFixture($temporary . '/oversized');
    $fixture->response = static fn(string $url, array $arguments) => ['response' => ['code' => 200], 'headers' => [], 'body' => str_repeat('x', $arguments['limit_response_size'] + 1)];
    codeClientRejects(fn() => $fixture->client()->stageNext(), 'transport ignoring its byte cap still fails closed');

    foreach (['http://github.com/file', 'https://github.com@127.0.0.1/file', 'https://attacker.example/file', 'https://github.com:8443/file'] as $index => $location) {
        $fixture = new CodeClientFixture($temporary . '/redirect-' . $index);
        $fixture->response = static fn() => ['response' => ['code' => 302], 'headers' => ['location' => $location], 'body' => ''];
        codeClientRejects(fn() => $fixture->client()->stageNext(), 'unsafe redirect is rejected before a follow-up request');
        codeClientCheck(count($fixture->httpCalls) === 1, 'redirect does not contact the unapproved origin');
    }

    $fixture = new CodeClientFixture($temporary . '/github-cdn');
    $fixture->response = static function (string $url, array $arguments, CodeClientFixture $fixture): ?array {
        if (str_ends_with($url, '/manifest.json')) {
            return ['response' => ['code' => 302], 'headers' => ['location' => 'https://release-assets.githubusercontent.com/fixture/manifest?token=test'], 'body' => ''];
        }
        return str_contains($url, '/fixture/manifest?') ? ['response' => ['code' => 200], 'headers' => [], 'body' => $fixture->manifestBytes] : null;
    };
    codeClientCheck($fixture->client()->stageNext()['manifest'] === $fixture->manifest, 'HTTPS GitHub release-asset CDN redirects retain exact-byte authentication');

    $fixture = new CodeClientFixture($temporary . '/redirect-loop');
    $fixture->response = static fn() => ['response' => ['code' => 302], 'headers' => ['location' => 'https://github.com/redirect-loop'], 'body' => ''];
    codeClientRejects(fn() => $fixture->client()->stageNext(), 'redirect loops have a finite request bound');
    codeClientCheck(count($fixture->httpCalls) === 4, 'at most three redirect hops are followed');

    $fixture = new CodeClientFixture($temporary . '/capacity');
    $fixture->settings['budgets']['code_staging_bytes'] = 1;
    codeClientRejects(fn() => $fixture->client()->stageNext(), 'signed inventory must fit its confirmed staging allocation');
    codeClientCheck($fixture->downloads === [], 'capacity failure does not fetch native programs');

    $fixture = new CodeClientFixture($temporary . '/account');
    $fixture->settings['budgets']['account_remaining_bytes'] = 1;
    codeClientRejects(fn() => $fixture->client()->stageNext(), 'account quota cannot be replaced with filesystem free space');

    $fixture = new CodeClientFixture($temporary . '/accounting');
    Files::json($fixture->directory . '/private/.usage-dirty.json', ['schema' => 1]);
    codeClientRejects(fn() => $fixture->client()->stageNext(), 'uncertified retained storage holds code updates');

    $fixture = new CodeClientFixture($temporary . '/ledger-snapshot');
    Files::json($fixture->directory . '/private/usage.json', $fixture->settings['retained_storage_usage']);
    unset($fixture->settings['retained_storage_usage']);
    codeClientCheck($fixture->client()->stageNext() !== null, 'trusted ledger snapshot supports callers without runtime usage injection');

    $fixture = new CodeClientFixture($temporary . '/integrity-and-replay');
    $previousCandidate = $fixture->client()->stageNext();
    $fixture->allowNextAttempt();
    $fixture->manifest['sequence'] = 2; $fixture->sign();
    $url = $fixture->manifest['files'][0]['url'];
    $originalBytes = $fixture->contents[$url];
    $fixture->contents[$url] = 'X' . substr($originalBytes, 1);
    codeClientRejects(fn() => $fixture->client()->stageNext(), 'per-file mismatch fails after authenticated metadata');
    codeClientCheck(file_get_contents($previousCandidate['path'] . '/bin/compact-scan') === $originalBytes,
        'failed new package leaves the previous staged program unchanged');
    codeClientCheck(glob($fixture->programs . '/staging/*') === [], 'failed program staging removes only its transient artifacts');
    $fixture->contents[$url] = $originalBytes;
    $fixture->manifest['sequence'] = 1; $fixture->sign(); $fixture->allowNextAttempt();
    codeClientRejects(fn() => $fixture->client()->stageNext(), 'interrupted newer sequence cannot be rolled back to an older accepted sequence');

    $fixture = new CodeClientFixture($temporary . '/lock');
    mkdir($fixture->programs, 0700);
    $lock = fopen($fixture->programs . '/code-update.lock', 'xb'); chmod($fixture->programs . '/code-update.lock', 0600);
    flock($lock, LOCK_EX);
    codeClientCheck($fixture->client()->stageNext() === null && $fixture->httpCalls === [], 'another staging worker causes a nonblocking deferral');
    flock($lock, LOCK_UN); fclose($lock);

    echo json_encode(['suite' => 'code-update-client', 'checks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
} finally {
    restore_error_handler();
    codeClientRemove($temporary);
}

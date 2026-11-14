<?php
declare(strict_types=1);
namespace WPClamAV\Scanning;

use WPClamAV\Storage\Files;

final class LocalBackend implements ScannerBackend
{
    private array $current;
    public function __construct(private string $binary, private string $state, private string $scratch, private ?ProcessRunner $runner = null,
        private ?string $certificates = null, private int $maxAgeSeconds = 172800, ?array $pinnedCurrent = null)
    {
        if (!str_starts_with($binary, '/') || is_link($binary) || !is_file($binary) || !is_executable($binary)) { throw new \RuntimeException('Configure a verified executable native scanner package.'); }
        if (!str_starts_with($state, '/') || is_link($state) || !is_dir($state)) { throw new \RuntimeException('Configure the private native state directory.'); }
        Files::privateDirectory($scratch);
        $this->certificates ??= dirname($binary, 2) . '/certs';
        if (!is_dir($this->certificates) || $this->maxAgeSeconds < 1) { throw new \RuntimeException('Configure official ClamAV trust certificates and a positive database freshness policy.'); }
        // One job uses one program/database tuple, even if activation replaces
        // current.json before the job acquires the shared native-state lock.
        $this->current = $pinnedCurrent ?? (file_exists($state . '/current.json') ? Files::readJson($state . '/current.json') : []);
        $this->runner ??= new ProcessRunner();
    }

    public function scan($original, array $receipt, string $profile, array $limits): array
    {
        $current = $this->current;
        if (($current['protocol'] ?? null) !== 1 || !preg_match('/\Agen-[a-f0-9]{64}\z/D', $current['generation'] ?? '')) { throw new \RuntimeException('No compatible native database generation is active.'); }
        $generation = $this->state . '/generations/' . $current['generation'];
        if (is_link($generation) || !is_dir($generation)) { throw new \RuntimeException('Active generation is not an immutable directory.'); }
        $path = $this->scratch . '/scan-' . bin2hex(random_bytes(16));
        $old = umask(0077);
        try { $copy = fopen($path, 'xb'); } finally { umask($old); }
        if ($copy === false) { throw new \RuntimeException('Cannot create a private scan snapshot.'); }
        $cpu = 0.0;
        try {
            rewind($original); $hash = hash_init('sha256'); $bytes = 0;
            while (!feof($original)) {
                CpuUsage::remaining($limits);
                $chunk = fread($original, 65536);
                if ($chunk === false) { throw new \RuntimeException('Cannot read preserved scan input.'); }
                if ($chunk === '') { if (!feof($original)) { throw new \RuntimeException('Preserved scan input stopped unexpectedly.'); } break; }
                $bytes += strlen($chunk);
                if ($bytes > $receipt['size'] || $bytes > $limits['file_bytes']) { throw new \RuntimeException('Scan snapshot exceeds its declared resource envelope.'); }
                hash_update($hash, $chunk); Files::writeAll($copy, $chunk);
            }
            Files::sync($copy);
            if ($bytes !== $receipt['size'] || !hash_equals($receipt['sha256'], hash_final($hash))) { throw new \RuntimeException('Original changed before scanning.'); }
            fclose($copy); $copy = null; chmod($path, 0400);
            $command = array_merge([$this->binary, 'scan', '--state', $this->state, '--generation', $generation, '--profile', $profile, '--input', $path,
                '--certs', $this->certificates, '--max-age-seconds', (string) $this->maxAgeSeconds], ProcessRunner::limitArguments(
                    array_replace($limits, ['wall_seconds' => CpuUsage::remaining($limits)])));
            $cpu = null;
            $response = $this->runner->run($command, $limits, $this->scratch);
            $cpu = $response['cpu_seconds'];
            if (!in_array($response['exit_code'], [0, 1, 3], true)) {
                $failure = json_decode(trim($response['stdout']), true);
                if (is_array($failure) && in_array('scanner-or-update-busy', $failure['reason_codes'] ?? [], true)) {
                    throw new ProcessFailure('The shared native state is busy.', $cpu, null, 'native_state_busy', (int) ($limits['now_epoch'] ?? time()) + 60);
                }
                throw new \RuntimeException('Native scan failed; no result is approved.');
            }
            $result = json_decode(trim($response['stdout']), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($result)) { throw new \RuntimeException('Invalid native result.'); }
            if (($result['generation_id'] ?? null) !== substr($current['generation'], 4)) { throw new \RuntimeException('Scanner result came from an unexpected generation.'); }
            $result['local_cpu_seconds'] = $response['cpu_seconds'];
            return ScanResult::validate($result, $receipt, $profile);
        } catch (ProcessFailure $failure) { throw $failure;
        } catch (\Throwable $failure) { throw new ProcessFailure($failure->getMessage(), $cpu, $failure);
        } finally {
            if (is_resource($copy)) { fclose($copy); }
            if (is_file($path)) { unlink($path); }
        }
    }
}

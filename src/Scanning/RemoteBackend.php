<?php
declare(strict_types=1);
namespace WPClamAV\Scanning;

final class RemoteBackend implements ScannerBackend
{
    public function __construct(private string $endpoint, private string $token, private ?string $caFile = null)
    {
        $parts = parse_url($endpoint);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) {
            throw new \RuntimeException('Remote scanning requires a fixed HTTPS endpoint without credentials or redirects.');
        }
        if ($token === '' || preg_match('/[\x00-\x20\x7f]/', $token)) { throw new \RuntimeException('Configure the remote scanner authentication token.'); }
    }

    public function scan($original, array $receipt, string $profile, array $limits): array
    {
        if (!function_exists('curl_init')) { throw new \RuntimeException('The streaming remote backend requires PHP cURL.'); }
        rewind($original);
        $handle = curl_init(rtrim($this->endpoint, '/') . '/v1/scan?profile=' . rawurlencode($profile));
        $response = ''; $overflow = false;
        $options = [CURLOPT_UPLOAD => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_INFILE => $original, CURLOPT_INFILESIZE => $receipt['size'],
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Content-Type: application/octet-stream', 'X-Content-SHA256: ' . $receipt['sha256'], 'Expect:'],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => (int) min(10000, $limits['wall_seconds'] * 1000), CURLOPT_TIMEOUT_MS => (int) ($limits['wall_seconds'] * 1000),
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$response, &$overflow, $limits): int {
                if (strlen($response) + strlen($chunk) > $limits['output_bytes']) { $overflow = true; return 0; }
                $response .= $chunk; return strlen($chunk);
            }];
        if ($this->caFile !== null) { $options[CURLOPT_CAINFO] = $this->caFile; }
        curl_setopt_array($handle, $options);
        try {
            if (curl_exec($handle) === false || $overflow) { throw new \RuntimeException('Remote scan failed, timed out or exceeded its protocol limit.'); }
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($status !== 200) { throw new \RuntimeException('Remote scanner did not return a completed result.'); }
            $result = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($result)) { throw new \RuntimeException('Invalid remote scan response.'); }
            return ScanResult::validate($result, $receipt, $profile);
        } finally { curl_close($handle); }
    }
}

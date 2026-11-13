<?php
declare(strict_types=1);
namespace WPClamAV\Resources;

use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\Files;
use WPClamAV\Storage\StorageException;

/** Limits retain their scope: PHP allocations, native AS and provider RSS are not interchangeable. */
final class ResourcePolicy
{
    private array $observed;
    private array $budgets;
    private ?FileSubmissionStore $store = null;
    private ?FileSubmissionStore $coordinator = null;
    private ?QuotaSnapshot $quota = null;
    private ?WorkLedger $work = null;

    public function __construct(private array $settings = [], ?array $observed = null)
    {
        $this->budgets = is_array($settings['budgets'] ?? null) ? $settings['budgets'] : [];
        $this->observed = $observed ?? self::detect();
    }

    public function bindStore(FileSubmissionStore $store, ?FileSubmissionStore $coordinator = null): void
    {
        $this->store = $store;
        if ($coordinator !== null || $this->coordinator === null) { $this->coordinator = $coordinator ?? $store; }
        $this->quota = new QuotaSnapshot($store, $this->budgets);
        $this->work = new WorkLedger($this->coordinator, $this->budgets);
    }

    public function beginNative(string $job, array $limits): ?string
    {
        if ($this->work === null) { return null; }
        return $this->work->reserve($job, max((float) $limits['cpu_seconds'], (float) $limits['wall_seconds']),
            (float) $limits['wall_seconds'] + (float) ($this->budget('cleanup_seconds') ?? 0));
    }

    public function finishNative(?string $token, ?float $cpuSeconds): void
    {
        if ($token !== null && $this->work !== null) { $this->work->complete($token, $cpuSeconds); }
    }

    public static function bytes(mixed $value, bool $zeroUnlimited = false): ?int
    {
        if ($value === null || $value === '' || $value === '-1' || $value === -1) { return null; }
        if (!is_int($value) && !is_string($value)) { throw new \InvalidArgumentException('A byte limit must be an integer or PHP size string.'); }
        if (!preg_match('/\A([0-9]+)\s*([KMGTP]?)B?\z/iD', trim((string) $value), $match)) { throw new \InvalidArgumentException('Invalid byte limit.'); }
        $factor = 1024 ** (strpos(' KMGTP', strtoupper($match[2])) ?: 0);
        $result = (float) $match[1] * $factor;
        if ($result > PHP_INT_MAX) { throw new \InvalidArgumentException('Byte limit overflows this PHP build.'); }
        return $zeroUnlimited && $result == 0 ? null : (int) $result;
    }

    public static function detect(): array
    {
        $settings = [];
        foreach (['upload_max_filesize', 'post_max_size', 'memory_limit', 'max_file_uploads', 'max_input_vars', 'max_input_time', 'max_execution_time', 'open_basedir', 'disable_functions'] as $key) {
            $value = ini_get($key);
            $settings[$key] = ['value' => $value === false ? null : $value, 'source' => 'PHP runtime', 'observed_at' => time(), 'scope' => str_contains($key, 'memory') ? 'php-process' : 'request'];
        }
        $settings['php_usage'] = ['value' => memory_get_usage(true), 'source' => 'memory_get_usage(true)', 'scope' => 'php-process', 'observed_at' => time()];
        if (function_exists('wp_max_upload_size')) { $settings['wp_upload_bytes'] = ['value' => wp_max_upload_size(), 'source' => 'WordPress filtered upload limit', 'scope' => 'file', 'observed_at' => time()]; }
        if (function_exists('is_multisite') && is_multisite() && function_exists('get_upload_space_available')) {
            $settings['multisite_remaining'] = ['value' => get_upload_space_available(), 'source' => 'WordPress multisite (private files counted separately)', 'scope' => 'storage', 'observed_at' => time()];
        }
        if (function_exists('posix_getrlimit')) {
            $limits = posix_getrlimit();
            foreach (['soft totalmem', 'soft maxproc', 'soft openfiles'] as $key) {
                $settings[$key] = ['value' => $limits[$key] ?? null, 'source' => 'getrlimit', 'scope' => 'native-inherited', 'observed_at' => time()];
            }
        }
        return $settings;
    }

    private function value(string $key): mixed { return $this->observed[$key]['value'] ?? null; }
    private function budget(string $key): ?float
    {
        $v = $this->budgets[$key] ?? null;
        if ($v === null || $v === '') { return null; }
        if (!is_numeric($v) || !is_finite((float) $v) || (float) $v < 0) { throw new \InvalidArgumentException('Invalid resource allocation: ' . $key); }
        return (float) $v;
    }
    private static function minimum(array $values): ?float
    {
        $values = array_values(array_filter($values, static fn($v): bool => $v !== null));
        return $values === [] ? null : min($values);
    }

    /** Policy, not provider entitlement; measured variability can increase the reserve. */
    public function margin(float $measured, float $variation = 0): float { return max($measured * 0.20, $variation); }

    public function fileLimit(array $context = []): ?int
    {
        $limits = [self::bytes($this->value('upload_max_filesize')), self::bytes($this->value('post_max_size'), true),
            $this->value('wp_upload_bytes'), $this->budget('file_bytes'), $this->budget('server_body_bytes')];
        if (isset($context['field_limit'])) { $limits[] = self::bytes($context['field_limit']); }
        if (isset($context['max_bytes'])) { $limits[] = self::bytes($context['max_bytes']); }
        $remaining = $this->budget('request_remaining_bytes');
        if ($remaining !== null) { $limits[] = $remaining; }
        $limit = self::minimum($limits);
        return $limit === null ? null : max(0, (int) floor($limit));
    }

    public function requestLimit(int $nonfileBytes, int $multipartOverhead): ?int
    {
        if ($nonfileBytes < 0 || $multipartOverhead < 0) { throw new \InvalidArgumentException('Negative request framing.'); }
        $limit = self::minimum([self::bytes($this->value('post_max_size'), true), $this->budget('server_body_bytes'), $this->budget('request_bytes')]);
        return $limit === null ? null : max(0, (int) $limit - $nonfileBytes - $multipartOverhead);
    }

    public function fileCount(?int $fieldCount = null): ?int
    {
        $php = $this->value('max_file_uploads');
        $count = self::minimum([$php === null ? null : (int) $php, $this->budget('files_per_request'), $fieldCount]);
        return $count === null ? null : max(0, (int) $count);
    }

    public function storageHeadroom(string $path, array $usage, int $requiredInodes = 3, bool $controlMetadata = false): array
    {
        $free = disk_free_space($path);
        $assigned = $this->budget('storage_bytes');
        $reserve = $this->budget('storage_reserve_bytes');
        $update = $this->budget('update_reserve_bytes');
        if ($assigned === null || $reserve === null || $update === null) {
            throw new StorageException('Confirm private-storage allocation, operating reserve and update workspace reserve in Diagnostics before accepting files.');
        }
        $snapshot = $this->quota?->remaining($usage);
        $accountRemaining = $snapshot['account_remaining_bytes'] ?? $this->budget('account_remaining_bytes');
        $limits = [$free === false ? null : $free, max(0, $assigned - (int) ($usage['bytes'] ?? 0)), $accountRemaining];
        $multisite = $this->value('multisite_remaining');
        if ($multisite !== null) { $limits[] = max(0, (int) $multisite - (int) ($usage['bytes'] ?? 0)); }
        $available = self::minimum($limits);
        if ($available === null) { throw new StorageException('Private storage availability is unknown.'); }
        $remainingInodes = $snapshot['inodes_remaining'] ?? $this->budget('inodes_remaining');
        $inodeReserve = $this->budget('inode_reserve');
        if ($requiredInodes < 0) { throw new \InvalidArgumentException('Negative inode requirement.'); }
        if ($remainingInodes !== null && $remainingInodes - ($controlMetadata ? 0 : ($inodeReserve ?? 0)) < $requiredInodes) {
            throw new StorageException('Insufficient inode capacity for this private-storage operation.');
        }
        return ['available_bytes' => max(0, (int) $available - (int) $reserve - (int) $update), 'operating_reserve_bytes' => (int) $reserve,
            'update_reserve_bytes' => (int) $update, 'account_remaining_known' => $accountRemaining !== null, 'quota_snapshot' => $snapshot];
    }

    public function admitStorage(string $path, ?int $expected, array $usage, array $context = []): void
    {
        $metadata = ($context['storage_operation'] ?? '') === 'metadata';
        $control = ($context['control_metadata'] ?? false) === true;
        $requiredInodes = (int) ($context['required_inodes'] ?? ($metadata ? 1 : 3));
        $headroom = $this->storageHeadroom($path, $usage, $requiredInodes, $control)['available_bytes'];
        $bytes = $metadata ? 0 : ($expected ?? $this->fileLimit($context));
        if ($bytes === null) { throw new StorageException('Upload length and admission ceiling are unknown.'); }
        $overhead = max(0, (int) ($context['storage_overhead_bytes'] ?? 0));
        if ($control) { $headroom += (int) ($this->budget('storage_reserve_bytes') ?? 0); }
        if ($bytes < 0 || $bytes + $this->margin($bytes) + $overhead > $headroom) { throw new StorageException('Insufficient reserved private capacity. Existing submissions have not been deleted; retry after capacity is added.'); }
        $fileLimit = $this->fileLimit($context);
        if (!$metadata && $fileLimit !== null && $bytes > $fileLimit) { throw new StorageException('File exceeds the effective upload limit.'); }
    }

    public function nativeLimits(bool $webRequest = true): array
    {
        $as = $this->budget('native_as_bytes');
        $provider = $this->budget('native_process_bytes');
        $account = $this->budget('native_account_available_bytes');
        // Virtual address space and resident memory have different scopes.
        $limits = [$as];
        $resident = self::minimum([$provider, $account]);
        $conservativeAs = $resident === null ? null : $resident * 0.8;
        if ($as !== null && $conservativeAs !== null && $as > $conservativeAs) {
            $measuredRss = $this->budget('measured_native_rss_bytes');
            $variation = $this->budget('measured_native_rss_variation_bytes') ?? 0;
            if ($measuredRss === null || $measuredRss + $this->margin($measuredRss, $variation) > $resident) {
                throw new \RuntimeException('Qualify native RSS separately before using an address-space cap above the conservative resident budget.');
            }
        } elseif ($as === null && $resident !== null) {
            $limits[] = $conservativeAs;
        }
        $inherited = $this->value('soft totalmem');
        if (is_numeric($inherited)) { $limits[] = (float) $inherited; }
        $as = self::minimum($limits);
        $wall = $this->budget($webRequest ? 'request_work_seconds' : 'worker_wall_seconds');
        if ($webRequest) {
            $php = $this->value('max_execution_time');
            if (is_numeric($php) && (float) $php > 0) { $wall = self::minimum([$wall, max(0, (float) $php - (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))))]); }
            $wall = self::minimum([$wall, $this->budget('proxy_remaining_seconds')]);
        }
        $cleanup = $this->budget('cleanup_seconds');
        $cpu = $this->budget('native_cpu_seconds');
        $file = $this->budget('native_file_bytes');
        if ($as === null || $as <= 0 || $wall === null || $wall <= 0 || $cleanup === null || $cpu === null || $cpu <= 0 || $file === null || $file <= 0) {
            throw new \RuntimeException('Native work is held: configure verified address-space, wall/CPU, cleanup and scratch-file bounds in Diagnostics.');
        }
        $wall -= $cleanup;
        if ($wall < 1) { throw new \RuntimeException('Insufficient time remains for a bounded scan and durable cleanup.'); }
        $cpuRemaining = $this->budget('cpu_remaining_seconds');
        if ($cpuRemaining !== null) { $cpu = min($cpu, $cpuRemaining); }
        if ($this->work !== null) {
            foreach ($this->work->remaining()['windows'] as $window) {
                if ($window['remaining_seconds'] !== null) { $cpu = min($cpu, $window['remaining_seconds']); }
            }
        }
        if ($cpu < 1) { throw new \RuntimeException('The allocated native CPU allowance is exhausted.'); }
        $output = (int) min(1048576, $as / 64, $this->phpHeadroom() / 8);
        if ($output < 4096) { throw new \RuntimeException('PHP has insufficient protocol-buffer headroom.'); }
        return ['as_bytes' => (int) $as, 'cpu_seconds' => (int) floor($cpu), 'wall_seconds' => $wall, 'file_bytes' => (int) $file, 'output_bytes' => $output];
    }

    public function phpHeadroom(): int
    {
        $limit = self::bytes($this->value('memory_limit'));
        $usage = max((int) ($this->value('php_usage') ?? 0), memory_get_usage(true));
        $allocation = $this->budget('php_work_bytes');
        $available = self::minimum([$limit === null ? null : max(0, $limit - $usage), $allocation]);
        return $available === null ? 0 : max(0, (int) ($available * 0.8));
    }

    public function remoteLimits(bool $webRequest = true): array
    {
        $wall = $this->budget($webRequest ? 'request_work_seconds' : 'worker_wall_seconds');
        $cleanup = $this->budget('cleanup_seconds');
        if ($webRequest) {
            $php = $this->value('max_execution_time');
            if (is_numeric($php) && (float) $php > 0) {
                $wall = self::minimum([$wall, max(0, (float) $php - (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))))]);
            }
            $wall = self::minimum([$wall, $this->budget('proxy_remaining_seconds')]);
        }
        if ($wall === null || $cleanup === null || $wall - $cleanup < 1) { throw new \RuntimeException('Configure a bounded remote work deadline and cleanup allowance.'); }
        $output = (int) min(1048576, $this->phpHeadroom() / 8);
        if ($output < 4096) { throw new \RuntimeException('Insufficient PHP protocol-buffer headroom.'); }
        return ['wall_seconds' => $wall - $cleanup, 'output_bytes' => $output];
    }

    public static function encodedAttachmentBytes(int $bytes): int
    {
        if ($bytes < 0 || $bytes > intdiv(PHP_INT_MAX, 8)) { throw new \InvalidArgumentException('Invalid attachment size.'); }
        $base64 = 4 * intdiv($bytes + 2, 3);
        return $base64 + 2 * (int) ceil($base64 / 76);
    }

    public function attachmentAllowed(int $bytes, int $encoded): bool
    {
        return (new MailCapacityGuard($this->budgets, $this->phpHeadroom()))->attachmentAllowed($bytes, $encoded);
    }

    public function messageAllowed(int $attachedBytes, int $encodedBytes): bool
    {
        return (new MailCapacityGuard($this->budgets, $this->phpHeadroom()))->messageAllowed($attachedBytes, $encodedBytes);
    }

    public function consumeMail(FileSubmissionStore $store, string $recipient, ?int $now = null): bool
    {
        $store = $this->coordinator ?? $store;
        $now ??= time();
        $allocation = self::minimum([$this->budget('mail_recipient_hourly'), $this->budget('mail_provider_hourly')]);
        $reserve = $this->budget('mail_reserve_hourly'); $other = $this->budget('other_mail_hourly');
        if ($allocation === null || $reserve === null || $other === null) { return false; }
        $cap = max(0, (int) floor($allocation - $reserve - $other));
        return $store->withLock('mail-budget', function () use ($store, $now, $cap): bool {
            $path = $store->directory() . '/mail-budget.json';
            $record = file_exists($path) ? Files::readJson($path) : ['attempts' => []];
            $attempts = array_values(array_filter($record['attempts'], static fn($t): bool => is_int($t) && $t > $now - 3600));
            if (count($attempts) >= $cap) { return false; }
            $attempts[] = $now;
            Files::json($path, ['attempts' => $attempts, 'observed_at' => $now]);
            return true;
        });
    }

    public function diagnostics(): array
    {
        $warnings = ['Accepted originals and submission records are retained indefinitely until explicit deletion.',
            'Filesystem free space is not necessarily your account quota. Private originals are accounted separately from WordPress uploads.',
            'Email attachments create independent copies; expiring an OTP or moving storage does not delete those copies.'];
        foreach (['account_remaining_bytes', 'inodes_remaining', 'native_account_available_bytes', 'other_mail_hourly'] as $key) {
            if ($this->budget($key) === null) { $warnings[] = $key . ' is unknown; no account-wide availability guarantee is made.'; }
        }
        $native = null;
        try { $native = $this->nativeLimits(false); } catch (\Throwable $e) { $warnings[] = $e->getMessage(); }
        return ['observations' => $this->observed, 'allocations' => $this->budgets, 'policy' => ['minimum_headroom_fraction' => 0.20, 'retention' => 'indefinite-explicit-deletion-only'],
            'effective_file_bytes' => $this->fileLimit(), 'effective_native' => $native,
            'application_record_bytes' => 1048576, 'cpu_accounting' => $this->work?->remaining(),
            'constraints' => ConstraintCatalog::build($this->observed, $this->budgets, [
                'file_bytes' => ['value' => $this->fileLimit(), 'unit' => 'bytes', 'scope' => 'file'],
                'native_as_bytes' => ['value' => $native['as_bytes'] ?? null, 'unit' => 'bytes', 'scope' => 'native-address-space']]),
            'warnings' => $warnings, 'observed_at' => time()];
    }
}

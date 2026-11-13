<?php
declare(strict_types=1);
namespace WPClamAV\Resources;

/** Resource facts with units, scope and provenance; references never authorize work. */
final class ConstraintCatalog
{
    private const OBSERVATIONS = [
        'upload_max_filesize' => ['bytes', 'file', 'php-bytes'],
        'post_max_size' => ['bytes', 'request', 'php-bytes-zero-unbounded'],
        'memory_limit' => ['bytes', 'php-process', 'php-bytes'],
        'php_usage' => ['bytes', 'php-process', 'number'],
        'wp_upload_bytes' => ['bytes', 'file', 'number'],
        'multisite_remaining' => ['bytes', 'wordpress-site-storage', 'number'],
        'max_file_uploads' => ['files', 'request', 'number'],
        'max_input_vars' => ['variables', 'request', 'number'],
        'max_input_time' => ['seconds', 'request-input', 'number'],
        'max_execution_time' => ['seconds', 'php-request-execution', 'positive'],
        'soft totalmem' => ['bytes', 'native-address-space', 'number'],
        'soft maxproc' => ['processes', 'native-inherited', 'number'],
        'soft openfiles' => ['descriptors', 'native-inherited', 'number'],
        'disk_free_bytes' => ['bytes', 'filesystem', 'number'],
        'request_nonfile_bytes' => ['bytes', 'request', 'number'],
        'request_multipart_overhead_bytes' => ['bytes', 'request', 'number'],
    ];

    private const ALLOCATIONS = [
        'file_bytes' => ['bytes', 'file'], 'server_body_bytes' => ['bytes', 'request'],
        'request_bytes' => ['bytes', 'request'], 'request_remaining_bytes' => ['bytes', 'request'],
        'files_per_request' => ['files', 'request'], 'storage_bytes' => ['bytes', 'private-retained-storage'],
        'storage_reserve_bytes' => ['bytes', 'private-operating-reserve'], 'update_reserve_bytes' => ['bytes', 'native-update-workspace'],
        'account_remaining_bytes' => ['bytes', 'hosting-account-remaining'], 'inodes_remaining' => ['inodes', 'hosting-account-remaining'],
        'inode_reserve' => ['inodes', 'hosting-account-reserve'], 'php_work_bytes' => ['bytes', 'php-additional-allocation'],
        'native_as_bytes' => ['bytes', 'native-address-space'], 'native_process_bytes' => ['bytes', 'native-process-resident'],
        'native_account_available_bytes' => ['bytes', 'native-account-resident-available'],
        'measured_native_rss_bytes' => ['bytes', 'native-process-resident-measured'],
        'measured_native_rss_variation_bytes' => ['bytes', 'native-process-resident-variation'],
        'native_file_bytes' => ['bytes', 'native-output-file'], 'native_cpu_seconds' => ['seconds', 'native-job-cpu'],
        'cpu_remaining_seconds' => ['seconds', 'native-current-cpu-available'],
        'cpu_hourly_seconds' => ['seconds', 'native-cpu-rolling-hour'], 'cpu_daily_seconds' => ['seconds', 'native-cpu-rolling-day'],
        'cpu_monthly_seconds' => ['seconds', 'native-cpu-rolling-31-days'],
        'request_work_seconds' => ['seconds', 'web-worker-wall'], 'worker_wall_seconds' => ['seconds', 'background-worker-wall'],
        'proxy_remaining_seconds' => ['seconds', 'request-proxy-wall'], 'cleanup_seconds' => ['seconds', 'worker-cleanup-reserve'],
        'mail_recipient_hourly' => ['recipients', 'mail-rolling-hour'], 'mail_provider_hourly' => ['recipients', 'mail-rolling-hour'],
        'mail_reserve_hourly' => ['recipients', 'mail-rolling-hour-reserve'], 'other_mail_hourly' => ['recipients', 'mail-other-hourly-use'],
        'mail_message_bytes' => ['bytes', 'complete-encoded-mail-message'], 'mail_attachment_bytes' => ['bytes', 'original-mail-attachments'],
        'mail_php_bytes' => ['bytes', 'php-mail-assembly'], 'mail_message_overhead_bytes' => ['bytes', 'mail-header-framing-reserve'],
        'mail_memory_multiplier' => ['ratio', 'php-mail-assembly'], 'database_max_age_seconds' => ['seconds', 'database-age'],
    ];

    private static function number(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || !is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) { return null; }
        $number = (float) $value;
        return $number <= PHP_INT_MAX && floor($number) === $number ? (int) $number : $number;
    }

    private static function entry(string $id, mixed $value, string $unit, string $scope, string $classification,
        string $source, ?int $observedAt, bool $referenceOnly = false): array
    {
        return ['id' => $id, 'value' => $value, 'unit' => $unit, 'scope' => $scope, 'classification' => $classification,
            'provenance' => ['source' => $source], 'observed_at' => $observedAt, 'known' => $value !== null,
            'unknown' => $value === null, 'limiting' => false, 'reference_only' => $referenceOnly];
    }

    private static function recommend(array &$entries, array $ids, string $unit, string $scope): array
    {
        $known = []; $unknown = [];
        foreach ($ids as $id) {
            if (!isset($entries[$id]) || $entries[$id]['unknown'] || $entries[$id]['reference_only']) { $unknown[] = $id; continue; }
            $known[$id] = $entries[$id]['value'];
        }
        $value = $known === [] ? null : min($known);
        $limiting = [];
        foreach ($known as $id => $constraint) {
            if ($constraint == $value) { $entries[$id]['limiting'] = true; $limiting[] = $id; }
        }
        return ['value' => $value, 'unit' => $unit, 'scope' => $scope, 'unknown' => $value === null,
            'qualification' => $value === null ? 'unknown' : 'known-upper-bound', 'complete' => $unknown === [],
            'constraints' => array_keys($known), 'limiting_constraints' => $limiting, 'unknown_constraints' => $unknown];
    }

    /**
     * Observations use ResourcePolicy::detect() records (value/source/scope/observed_at).
     * Allocations are explicitly configured operator values, never provider defaults.
     * Effective values may be records {value,unit,scope,source} or scalar numbers;
     * callers supply only values already calculated by their operative policy.
     */
    public static function build(array $observations, array $allocations, array $effective = [], ?int $observedAt = null): array
    {
        $now = $observedAt ?? time(); $entries = [];
        foreach (self::OBSERVATIONS as $key => [$unit, $scope, $format]) {
            $record = $observations[$key] ?? []; $raw = $record['value'] ?? null;
            try {
                $value = str_starts_with($format, 'php-bytes')
                    ? ResourcePolicy::bytes($raw, $format === 'php-bytes-zero-unbounded') : self::number($raw);
            } catch (\Throwable) { $value = null; }
            if ($format === 'positive' && $value === 0) { $value = null; }
            $id = 'observed.' . $key;
            $entries[$id] = self::entry($id, $value, $unit, (string) ($record['scope'] ?? $scope), 'observed',
                (string) ($record['source'] ?? 'No runtime observation available'), isset($record['observed_at']) ? (int) $record['observed_at'] : null);
            if ($value === null && $raw !== null) { $entries[$id]['unusable_raw_value'] = is_scalar($raw) ? $raw : '[invalid observation]'; }
        }
        foreach ($observations as $key => $record) {
            if (isset(self::OBSERVATIONS[$key]) || !is_array($record)) { continue; }
            $id = 'observed.' . $key; $value = $record['value'] ?? null;
            $entries[$id] = self::entry($id, is_scalar($value) ? $value : null, 'configuration',
                (string) ($record['scope'] ?? 'runtime-configuration'), 'observed', (string) ($record['source'] ?? 'Runtime observation'),
                isset($record['observed_at']) ? (int) $record['observed_at'] : null);
        }
        foreach (self::ALLOCATIONS as $key => [$unit, $scope]) {
            $id = 'allocation.' . $key;
            $entries[$id] = self::entry($id, self::number($allocations[$key] ?? null), $unit, $scope, 'user',
                'Explicit operator allocation; confirmation of hosting entitlement and other account activity is required',
                isset($allocations['quota_observed_at']) && in_array($key, ['account_remaining_bytes', 'inodes_remaining'], true)
                    ? (int) $allocations['quota_observed_at'] : null);
        }
        foreach ([
            'metadata_record_bytes' => [1048576, 'bytes', 'canonical-json-record', 'Application format/read ceiling, not a hosting quota'],
            'minimum_headroom_fraction' => [0.20, 'ratio', 'work-reserve', 'Implementation minimum safety headroom; measured variability can increase it'],
            'cpu_monthly_window_seconds' => [2678400, 'seconds', 'cpu-accounting', 'Conservative rolling 31-day accounting, separate from provider calendar-month references'],
        ] as $key => [$value, $unit, $scope, $source]) {
            $id = 'policy.' . $key; $entries[$id] = self::entry($id, $value, $unit, $scope, 'policy', $source, null);
        }
        $recommendations = [
            'file_bytes' => self::recommend($entries, ['observed.upload_max_filesize', 'observed.wp_upload_bytes', 'allocation.file_bytes'], 'bytes', 'file'),
            'request_body_bytes' => self::recommend($entries, ['observed.post_max_size', 'allocation.server_body_bytes', 'allocation.request_bytes'], 'bytes', 'request'),
            'files_per_request' => self::recommend($entries, ['observed.max_file_uploads', 'allocation.files_per_request'], 'files', 'request'),
            'native_address_space_bytes' => self::recommend($entries, ['observed.soft totalmem', 'allocation.native_as_bytes'], 'bytes', 'native-address-space'),
            'native_resident_bytes' => self::recommend($entries, ['allocation.native_process_bytes', 'allocation.native_account_available_bytes'], 'bytes', 'native-resident'),
        ];
        $mail = self::recommend($entries, ['allocation.mail_recipient_hourly', 'allocation.mail_provider_hourly'], 'recipients', 'mail-rolling-hour');
        $mailSubtractions = ['allocation.mail_reserve_hourly', 'allocation.other_mail_hourly'];
        foreach ($mailSubtractions as $id) { if ($entries[$id]['unknown']) { $mail['unknown_constraints'][] = $id; } }
        $mail['complete'] = $mail['unknown_constraints'] === [];
        $mail['value'] = $mail['complete'] ? max(0, (int) floor($mail['value'] - $entries[$mailSubtractions[0]]['value'] - $entries[$mailSubtractions[1]]['value'])) : null;
        $mail['unknown'] = $mail['value'] === null;
        $mail['qualification'] = $mail['unknown'] ? 'unknown' : 'confirmed-allocation-minus-reserves';
        $mail['constraints'] = array_merge($mail['constraints'], $mailSubtractions);
        $recommendations['mail_recipients_hourly'] = $mail;
        // Request framing is subtracted only when both measured components exist.
        $framing = ['observed.request_nonfile_bytes', 'observed.request_multipart_overhead_bytes'];
        $request = $recommendations['request_body_bytes'];
        if ($request['value'] !== null && !$entries[$framing[0]]['unknown'] && !$entries[$framing[1]]['unknown']) {
            $value = max(0, $request['value'] - $entries[$framing[0]]['value'] - $entries[$framing[1]]['value']);
            $recommendations['request_file_payload_bytes'] = array_replace($request, ['value' => $value, 'scope' => 'request-file-payload',
                'constraints' => array_merge($request['constraints'], $framing)]);
        } else {
            $recommendations['request_file_payload_bytes'] = ['value' => null, 'unit' => 'bytes', 'scope' => 'request-file-payload',
                'unknown' => true, 'complete' => false, 'qualification' => 'unknown', 'constraints' => [],
                'limiting_constraints' => [], 'unknown_constraints' => array_merge($request['unknown_constraints'], array_filter($framing, static fn($id) => $entries[$id]['unknown']))];
        }
        foreach ($effective as $key => $record) {
            $record = is_array($record) ? $record : ['value' => $record]; $id = 'effective.' . $key;
            $entries[$id] = self::entry($id, self::number($record['value'] ?? null), (string) ($record['unit'] ?? 'unspecified'),
                (string) ($record['scope'] ?? 'effective-policy'), 'policy', (string) ($record['source'] ?? 'Operative resource policy calculation'), $now);
        }
        foreach (self::growBigReferences() as $id => $entry) { $entries[$id] = $entry; }
        return ['schema' => 1, 'observed_at' => $now, 'constraints' => $entries, 'recommendations' => $recommendations,
            'warnings' => ['Known upper bounds do not authorize work while another required constraint is unknown.',
                'GrowBig references are dated documentation, not measured limits, remaining quota, or default allocations.',
                'PHP memory, native virtual address space and native resident memory have distinct scopes.',
                'Multipart framing, form fields, canonical metadata, update workspace and retained originals require separate capacity.',
                'Unknown or unbounded runtime settings do not establish available hosting capacity.']];
    }

    public static function growBigReferences(): array
    {
        $facts = [
            'process_resident_bytes' => [805306368, 'bytes', 'process-resident-reference', 'fair-use-siteground-hosting', '768 MiB reference; confirm provider units and current plan'],
            'cpu_hourly_seconds' => [2000, 'seconds', 'provider-hourly-cpu-soft-threshold', 'fair-use-siteground-hosting', 'Soft fair-use threshold, not reserved CPU entitlement'],
            'cpu_daily_seconds' => [20000, 'seconds', 'provider-daily-cpu-soft-threshold', 'fair-use-siteground-hosting', 'Soft fair-use threshold, not reserved CPU entitlement'],
            'cpu_monthly_seconds' => [600000, 'seconds', 'provider-calendar-month-cpu-soft-threshold', 'fair-use-siteground-hosting', 'Soft calendar-month threshold; plugin uses conservative rolling 31 days'],
            'account_inode_limit' => [400000, 'inodes', 'hosting-account-total-reference', 'fair-use-siteground-hosting', 'Total account reference, never remaining inode capacity'],
            'mail_recipients_hourly' => [400, 'recipients', 'hosting-account-mail-hour-reference', 'acceptable-use-sitegrounds-email-service', 'Account recipients, including other site and mailbox traffic'],
            'cron_minimum_interval_seconds' => [1800, 'seconds', 'hosting-cron-reference', 'cron-jobs', '30-minute reference minimum; verify current account scheduler policy'],
        ];
        $entries = [];
        foreach ($facts as $key => [$value, $unit, $scope, $slug, $note]) {
            $id = 'reference.siteground_growbig.' . $key;
            $entry = self::entry($id, $value, $unit, $scope, 'provider', 'SiteGround documented GrowBig reference', null, true);
            $entry['provenance']['url'] = 'https://www.siteground.com/kb/' . $slug . '/';
            $entry['provenance']['reference_date'] = '2026-11-08';
            $entry['provenance']['note'] = $note;
            $entry['confirmed_for_site'] = false;
            $entries[$id] = $entry;
        }
        return $entries;
    }
}

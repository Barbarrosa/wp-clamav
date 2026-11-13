<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'WPClamAV\\')) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});
use WPClamAV\Resources\ConstraintCatalog;
use WPClamAV\Resources\MailCapacityGuard;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    ++$checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
};
$rejects = static function (callable $operation, string $message) use ($check): void {
    try { $operation(); } catch (Throwable) { $check(true, $message); return; }
    $check(false, $message);
};
$unknown = ConstraintCatalog::build([], [], [], 1700000000);
$constraints = $unknown['constraints'];
$check($unknown['recommendations']['file_bytes']['value'] === null, 'empty environment produces no invented upload limit');
$check($unknown['recommendations']['native_resident_bytes']['value'] === null, 'provider references do not become RSS capacity');
$check($unknown['recommendations']['mail_recipients_hourly']['value'] === null, '400-provider reference never authorizes mail');
$check($constraints['allocation.account_remaining_bytes']['unknown'], 'unconfirmed account capacity remains unknown');
$check($constraints['policy.metadata_record_bytes']['value'] === 1048576 && $constraints['policy.metadata_record_bytes']['classification'] === 'policy', 'JSON format ceiling is explicit application policy');
$check($constraints['policy.minimum_headroom_fraction']['value'] === 0.20, 'minimum reserve documented with provenance');
foreach (ConstraintCatalog::growBigReferences() as $entry) {
    $check($entry['classification'] === 'provider' && $entry['reference_only'] && !$entry['confirmed_for_site'] && !$entry['limiting'], 'every provider entry is non-operative reference');
    $check(isset($entry['provenance']['url'], $entry['provenance']['reference_date']) && $entry['observed_at'] === null, 'provider publication reference is distinct from runtime observation');
}
$observations = [
    'upload_max_filesize' => ['value' => '32M', 'source' => 'PHP runtime', 'observed_at' => 123],
    'post_max_size' => ['value' => '16M', 'source' => 'PHP runtime', 'observed_at' => 123],
    'wp_upload_bytes' => ['value' => 8388608, 'source' => 'WordPress filter', 'observed_at' => 124],
    'memory_limit' => ['value' => '-1', 'source' => 'PHP runtime', 'observed_at' => 123],
    'soft totalmem' => ['value' => 536870912, 'source' => 'getrlimit', 'observed_at' => 125],
    'disable_functions' => ['value' => 'exec,proc_open', 'source' => 'PHP runtime', 'observed_at' => 123],
];
$budgets = ['native_process_bytes' => 1000000, 'file_bytes' => 10485760, 'server_body_bytes' => 20971520,
    'mail_recipient_hourly' => 50, 'mail_provider_hourly' => 400, 'mail_reserve_hourly' => 5, 'other_mail_hourly' => 10,
    'account_remaining_bytes' => 100000, 'quota_observed_at' => 150];
$catalog = ConstraintCatalog::build($observations, $budgets, ['file_bytes' => ['value' => 8388608, 'unit' => 'bytes', 'scope' => 'file']], 1700000000);
$constraints = $catalog['constraints'];
$check($catalog['recommendations']['file_bytes']['value'] === 8388608, 'only comparable confirmed upload bounds are minimized');
$check($constraints['observed.wp_upload_bytes']['limiting'] && !$constraints['observed.upload_max_filesize']['limiting'], 'binding constraint identified');
$check($constraints['observed.wp_upload_bytes']['observed_at'] === 124 && $constraints['observed.wp_upload_bytes']['provenance']['source'] === 'WordPress filter', 'observation timestamp and source preserved');
$check($constraints['observed.memory_limit']['unknown'], 'unbounded PHP configuration does not imply known remaining memory');
$check($constraints['observed.disable_functions']['value'] === 'exec,proc_open', 'nonnumeric capability observations preserved');
$check($catalog['recommendations']['native_address_space_bytes']['value'] === 536870912, 'RSS allocation never silently constrains address-space diagnostic');
$check($catalog['recommendations']['native_resident_bytes']['value'] === 1000000, 'RSS remains distinct from address-space');
$check(!$catalog['recommendations']['native_resident_bytes']['complete'], 'known process RSS bound does not imply account remainder is known');
$check($catalog['recommendations']['request_file_payload_bytes']['value'] === null, 'unknown multipart framing cannot yield an exact payload recommendation');
$check($catalog['recommendations']['mail_recipients_hourly']['value'] === 35, 'mail allocation deducts account traffic and reserve');
$check($constraints['allocation.account_remaining_bytes']['observed_at'] === 150, 'operator quota observation timestamp retained');
$check($constraints['effective.file_bytes']['value'] === 8388608, 'operative policy values recorded separately');
$observations['request_nonfile_bytes'] = ['value' => 3000]; $observations['request_multipart_overhead_bytes'] = ['value' => 1000];
$catalog = ConstraintCatalog::build($observations, $budgets);
$check($catalog['recommendations']['request_file_payload_bytes']['value'] === 16777216 - 4000, 'known framing deducted from narrowest request body cap');
unset($budgets['other_mail_hourly']);
$check(ConstraintCatalog::build($observations, $budgets)['recommendations']['mail_recipients_hourly']['value'] === null, 'unknown other site mail prevents invented recipient allocation');
$check(ConstraintCatalog::build(['post_max_size' => ['value' => 'invalid']], [])['constraints']['observed.post_max_size']['unknown'], 'malformed runtime limits remain unknown');

$budgets = ['mail_message_bytes' => 10000, 'mail_php_bytes' => 100000, 'mail_message_overhead_bytes' => 1000, 'mail_memory_multiplier' => 3];
$guard = new MailCapacityGuard($budgets, 100000);
$check($guard->messageAllowed(0, 2000), 'data-only mail permitted from complete explicit budgets');
$check($guard->message(0, 2000)['required_php_bytes'] === 6000, 'complete encoded message memory accounted');
$check(!$guard->messageAllowed(0, 10001), 'complete message cap applies without attachment');
$check(!$guard->messageAllowed(0, 999), 'estimate cannot omit explicit framing allowance');
$check(!(new MailCapacityGuard($budgets, 5999))->messageAllowed(0, 2000), 'current PHP headroom constrains report-only mail');
$check(!(new MailCapacityGuard(array_replace($budgets, ['mail_php_bytes' => 5999]), 100000))->messageAllowed(0, 2000), 'configured assembly allocation independently constrains report-only mail');
$check(!$guard->messageAllowed(40000, 2000), 'original plus encoding counted for attachment assembly');
$check(!$guard->messageAllowed(0, 10001), 'body-only mail cannot evade mail-message limit');
foreach (array_keys($budgets) as $key) {
    $missing = $budgets; unset($missing[$key]);
    $result = (new MailCapacityGuard($missing, 100000))->message(0, 2000);
    $check(!$result['allowed'] && in_array($key . '_unknown', $result['reason_codes'], true), 'each missing mail prerequisite is explicit');
}
$check(!(new MailCapacityGuard(array_replace($budgets, ['mail_memory_multiplier' => 0.5]), 100000))->messageAllowed(0, 2000), 'under-counting assembly multiplier rejected');
$check((new MailCapacityGuard($budgets + ['mail_attachment_bytes' => 5], 100000))->messageAllowed(0, 2000), 'large retained original is irrelevant to data-only mail');
$check(!(new MailCapacityGuard($budgets + ['mail_attachment_bytes' => 5], 100000))->messageAllowed(6, 2000), 'raw-attachment cap independent of encoded-message cap');
$check(MailCapacityGuard::encodedAttachmentBytes(57) === 78, 'MIME line folding included');
$check(MailCapacityGuard::estimatedMessageBytes('subject', 'body', [57], 1000) === 1000 + 44 + 78, 'text encoding, framing and attachment charged exactly once');
$check($guard->attachmentAllowed(57, 78), 'early attachment check includes framing');
$rejects(static fn() => $guard->attachment(57, 76), 'underestimated encoding rejected');
$rejects(static fn() => $guard->message(-1, 1000), 'negative originals rejected');
$rejects(static fn() => $guard->message(PHP_INT_MAX, 1), 'memory sum overflow rejected');
$rejects(static fn() => MailCapacityGuard::estimatedMessageBytes('', '', [PHP_INT_MAX], 1000), 'attachment encoding overflow rejected');
$rejects(static fn() => MailCapacityGuard::estimatedMessageBytes('a', '', [], PHP_INT_MAX), 'message summation overflow rejected');
$rejects(static fn() => (new MailCapacityGuard(['mail_memory_multiplier' => INF], 100000))->message(0, 1000), 'nonfinite allocations rejected');
$rejects(static fn() => new MailCapacityGuard([], -1), 'negative PHP headroom rejected');

echo json_encode(['suite' => 'resource-constraints', 'checks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";

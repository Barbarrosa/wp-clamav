<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'WPClamAV\\')) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});

use WPClamAV\Resources\NetworkAllocations;
use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\Files;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    ++$checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
};
$rejects = static function (callable $operation, string $message) use ($check): void {
    try { $operation(); } catch (Throwable) { $check(true, $message); return; }
    $check(false, $message);
};
$root = sys_get_temp_dir() . '/wp-clamav-network-allocations-' . bin2hex(random_bytes(12));
mkdir($root, 0700);
$raw = ['storage_bytes' => 6291456, 'account_remaining_bytes' => 2097152, 'inodes_remaining' => 200,
    'quota_observed_at' => 1700000000, 'storage_reserve_bytes' => 32768, 'update_reserve_bytes' => 32768,
    'inode_reserve' => 5, 'file_bytes' => 2097152];
$stream = static function (string $bytes) { $input = fopen('php://temp', 'w+b'); fwrite($input, $bytes); rewind($input); return $input; };
try {
    $coordinator = new FileSubmissionStore($root . '/account-control');
    $first = NetworkAllocations::allocate($coordinator, $raw, 2);
    $check(!$first['diagnostics']['held'], 'first observed site count establishes confirmation');
    $check($first['budgets']['storage_bytes'] === 3145728, 'network allocation is shared');
    $check($first['budgets']['account_remaining_bytes'] === 1048576, 'account remainder is not granted in full per site');
    $check($first['budgets']['inodes_remaining'] === 100, 'network inode remainder is shared');
    $check($first['budgets']['storage_reserve_bytes'] === $raw['storage_reserve_bytes']
        && $first['budgets']['update_reserve_bytes'] === $raw['update_reserve_bytes'], 'operational reserves remain separate and undivided');
    $check($first['diagnostics']['confirmed_network_quotas']['account_remaining_bytes'] === 2097152, 'diagnostics preserve original account observation');
    $check(is_file($coordinator->directory() . '/network-allocation.json'), 'confirmation count is durable in the shared coordinator');
    $observed = ['upload_max_filesize' => ['value' => '8M'], 'post_max_size' => ['value' => '8M']];
    $policies = []; $stores = [];
    foreach ([1, 2] as $number) {
        $allocation = NetworkAllocations::allocate($coordinator, $raw, 2);
        $policy = new ResourcePolicy(['budgets' => $allocation['budgets']], $observed);
        $path = $root . '/site-' . $number;
        $store = new FileSubmissionStore($path, [], static fn(?int $length, array $usage, array $context) => $policy->admitStorage($path, $length, $usage, $context));
        $policy->bindStore($store, $coordinator);
        $policy->storageHeadroom($path, $store->usage());
        $input = $stream(str_repeat((string) $number, 600000));
        $receipt = $store->ingestStream($input, ['size' => 600000, 'name' => 'network.bin']); fclose($input);
        $check($receipt['complete'], 'site ' . $number . ' can preserve data within its confirmed share');
        $rejects(function () use ($store, $stream): void {
            $input = $stream(str_repeat('q', 400000));
            try { $store->ingestStream($input, ['size' => 400000, 'name' => 'over-share.bin']); }
            finally { fclose($input); }
        }, 'site ' . $number . ' cannot reuse its original account remainder after growth');
        $quota = $policy->storageHeadroom($path, $store->usage())['quota_snapshot'];
        $check($quota['account_remaining_bytes'] < 500000, 'site ' . $number . ' private growth reduces only its conservative share');
        $policies[] = $policy; $stores[] = $store;
    }
    $repeat = NetworkAllocations::allocate($coordinator, $raw + ['native_cpu_seconds' => 90], 2);
    $check($repeat['diagnostics']['confirmation_sha256'] === $first['diagnostics']['confirmation_sha256'], 'unrelated setting changes do not create a quota confirmation');
    $before = Files::readJson($stores[0]->directory() . '/quota-snapshot.json');
    $samePolicy = new ResourcePolicy(['budgets' => $repeat['budgets']], $observed); $samePolicy->bindStore($stores[0], $coordinator);
    $same = $samePolicy->storageHeadroom($stores[0]->directory(), $stores[0]->usage())['quota_snapshot'];
    $check($same['baseline_bytes'] === $before['baseline_bytes'], 'repeated network allocation does not reset site growth baseline');
    $added = NetworkAllocations::allocate($coordinator, $raw, 3);
    $check($added['diagnostics']['held'] && $added['budgets']['storage_bytes'] === 0
        && $added['budgets']['account_remaining_bytes'] === 0 && $added['budgets']['inodes_remaining'] === 0,
        'new site count cannot silently redistribute already spent capacity');
    $back = NetworkAllocations::allocate($coordinator, $raw, 2);
    $check($back['diagnostics']['held'], 'returning to the old count does not silently clear a durable hold');
    $changedStorage = NetworkAllocations::allocate($coordinator, array_replace($raw, ['storage_bytes' => 8388608]), 3);
    $check($changedStorage['diagnostics']['held'], 'changing private allocation alone is not a fresh account observation');
    $refresh = NetworkAllocations::allocate($coordinator, array_replace($raw, ['quota_observed_at' => 1700001000]), 3);
    $check(!$refresh['diagnostics']['held'] && $refresh['budgets']['account_remaining_bytes'] === intdiv(2097152, 3), 'fresh explicit observation permits conservative redistribution');
    $check($refresh['diagnostics']['confirmed_site_count'] === 3, 'new confirmed site count persists');
    $stale = NetworkAllocations::allocate($coordinator, $raw, 2);
    $check($stale['diagnostics']['held'], 'regressed observation cannot reset allocation');
    $explicit = NetworkAllocations::allocate($coordinator, array_replace($raw,
        ['quota_observed_at' => 1700001000, 'account_remaining_bytes' => 1048576]), 2);
    $check(!$explicit['diagnostics']['held'] && $explicit['budgets']['account_remaining_bytes'] === 524288,
        'explicit changed known quota with current timestamp permits new confirmation');
    $unknownStore = new FileSubmissionStore($root . '/unknown-account-control');
    $unknown = NetworkAllocations::allocate($unknownStore, ['storage_bytes' => 1000], 2);
    $check(!array_key_exists('account_remaining_bytes', $unknown['budgets'])
        && !array_key_exists('inodes_remaining', $unknown['budgets'])
        && $unknown['diagnostics']['per_site']['account_remaining_bytes'] === null, 'unknown remainder and inode capacity remain unknown');
    $unknownChanged = NetworkAllocations::allocate($unknownStore, ['storage_bytes' => 1000], 3);
    $check($unknownChanged['diagnostics']['held'] && $unknownChanged['budgets']['storage_bytes'] === 0
        && !array_key_exists('account_remaining_bytes', $unknownChanged['budgets']), 'site-count hold does not invent a known quota');
    $unknownRefresh = NetworkAllocations::allocate($unknownStore, ['storage_bytes' => 1000, 'quota_observed_at' => 1700002000], 3);
    $check(!$unknownRefresh['diagnostics']['held'] && $unknownRefresh['budgets']['storage_bytes'] === 333,
        'explicit observation time can confirm a new network private share while account remainder stays unknown');
    $rejects(fn() => NetworkAllocations::allocate($coordinator, $raw, 0), 'zero sites rejected');
    $rejects(fn() => NetworkAllocations::allocate($coordinator, array_replace($raw, ['inodes_remaining' => -1]), 2), 'negative quota rejected');
    $saved = Files::readJson($coordinator->directory() . '/network-allocation.json');
    $saved['observations']['account_remaining_bytes'] += 1;
    Files::json($coordinator->directory() . '/network-allocation.json', $saved);
    $rejects(fn() => NetworkAllocations::allocate($coordinator, $raw, 2), 'corrupt confirmation cannot invent fresh capacity');
    echo json_encode(['suite' => 'network-allocations', 'checks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname()); }
    @rmdir($root);
}

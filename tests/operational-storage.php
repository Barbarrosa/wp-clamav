<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'WPClamAV\\')) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});
use WPClamAV\Resources\OperationalStorage;
use WPClamAV\Resources\QuotaSnapshot;
use WPClamAV\Resources\ResourcePolicy;
use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\Files;
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { ++$checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $message); } };
$rejects = static function (callable $call, string $message) use ($check): void { try { $call(); } catch (Throwable) { $check(true, $message); return; } $check(false, $message); };
$root = sys_get_temp_dir() . '/wp-clamav-operations-' . bin2hex(random_bytes(12)); mkdir($root, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) { foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $child) { $remove($child->getPathname()); } rmdir($path); }
    elseif (file_exists($path) || is_link($path)) { unlink($path); }
};
try {
    $store = new FileSubmissionStore($root . '/private');
    $state = $store->directory() . '/native-state'; $programs = $store->directory() . '/programs'; $scratch = $store->scratchDirectory();
    $measure = new OperationalStorage([$state, $programs, $scratch], [$store->directory()], 1000, 2);
    $initial = $measure->measure();
    $check($initial['complete'] && $initial['objects'] === 1, 'missing future roots are zero while existing scratch directory is counted');
    mkdir($state, 0700); mkdir($programs, 0700); mkdir($state . '/generations', 0700);
    file_put_contents($state . '/generations/database', str_repeat('d', 100000));
    file_put_contents($programs . '/engine', str_repeat('e', 200000));
    file_put_contents($scratch . '/temporary', str_repeat('t', 10000));
    $usage = $measure->measure(); $expectedLogical = 0; $expectedAllocated = 0; $expectedObjects = 0;
    foreach ([$state, $programs, $scratch] as $path) {
        $stat = lstat($path); $expectedLogical += $stat['size']; $expectedAllocated += $stat['blocks'] * 512; ++$expectedObjects;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $stat = lstat($entry->getPathname()); $expectedLogical += $stat['size']; $expectedAllocated += $stat['blocks'] * 512; ++$expectedObjects;
        }
    }
    $check($usage['logical_bytes'] === $expectedLogical && $usage['allocated_bytes'] === $expectedAllocated && $usage['objects'] === $expectedObjects, 'actual logical bytes, allocated blocks and directory/file inodes measured');
    $check($usage['bytes'] === max($expectedLogical, $expectedAllocated), 'admission uses the larger actual storage quantity');
    $check((new OperationalStorage([$state, $state . '/generations', $state], [$store->directory()], 1000, 2))->measure()['objects'] === 3, 'overlapping configured roots count each operational inode once');
    $beforeCanonical = $store->usage();
    file_put_contents($programs . '/separate', str_repeat('p', 20000));
    $check($store->usage()['bytes'] === $beforeCanonical['bytes'], 'operational code is not charged to canonical retained allocation');
    $rejects(static fn() => new OperationalStorage([$store->directory()], [$store->directory()]), 'canonical root cannot be traversed as operational');
    $rejects(static fn() => new OperationalStorage([$store->directory() . '/objects'], [$store->directory()]), 'original objects namespace cannot be traversed');
    $rejects(static fn() => new OperationalStorage([$root], [$store->directory()]), 'operational ancestor cannot accidentally traverse retained originals');
    $rejects(static fn() => new OperationalStorage(['relative/path']), 'relative roots rejected');
    $rejects(static fn() => new OperationalStorage([$root . '/a/../b']), 'parent traversal rejected');
    $rejects(static fn() => (new OperationalStorage([$programs], [], 1, 2))->measure(), 'entry budget overflow holds rather than returning incomplete totals');
    $clock = (object) ['ticks' => 0];
    $rejects(static fn() => (new OperationalStorage([$programs], [], 1000, 0.1, static function () use ($clock): float { return (float) $clock->ticks++; }))->measure(), 'time budget overflow holds');
    symlink($root, $state . '/symlink');
    $rejects(static fn() => $measure->measure(), 'operational child symlink holds instead of traversing outside roots'); unlink($state . '/symlink');
    symlink($state, $root . '/linked-state');
    $rejects(static fn() => (new OperationalStorage([$root . '/linked-state/generations']))->measure(), 'root ancestor symlink rejected'); unlink($root . '/linked-state');
    link($programs . '/engine', $programs . '/hardlink');
    $rejects(static fn() => $measure->measure(), 'hard-linked files cannot falsify operational allocation'); unlink($programs . '/hardlink');
    $sparse = fopen($programs . '/sparse', 'x+b'); ftruncate($sparse, 1048576); fclose($sparse);
    $sparseUsage = $measure->measure();
    $check($sparseUsage['logical_bytes'] >= $sparseUsage['allocated_bytes'], 'sparse files retain distinct logical and allocated diagnostics'); unlink($programs . '/sparse');

    $quotaRoot = new FileSubmissionStore($root . '/quota'); $opPath = $quotaRoot->directory() . '/programs';
    $ops = new OperationalStorage([$opPath], [$quotaRoot->directory()], 1000, 2);
    $budgets = ['account_remaining_bytes' => 2000000, 'inodes_remaining' => 200, 'quota_observed_at' => 1700000000];
    $quota = new QuotaSnapshot($quotaRoot, $budgets, static fn(): array => $ops->measure());
    $base = $quota->remaining($quotaRoot->usage());
    mkdir($opPath, 0700); file_put_contents($opPath . '/release1', str_repeat('r', 100000));
    $first = $quota->remaining([]);
    $check($first['account_remaining_bytes'] < $base['account_remaining_bytes'] - 100000, 'new code reduces confirmed account remainder even if supplied canonical snapshot is empty');
    $check($first['inodes_remaining'] <= $base['inodes_remaining'] - 2, 'operational directories and files reduce remaining inodes');
    $check($first['operational_growth_bytes'] === $ops->measure()['bytes'], 'separate operational baseline charges new code once');
    file_put_contents($opPath . '/release2', str_repeat('s', 50000));
    $second = $quota->remaining($quotaRoot->usage());
    $check($second['account_remaining_bytes'] <= $first['account_remaining_bytes'] - 50000, 'repeated releases cannot reuse the original confirmed account quota');
    $check($second['baseline_bytes'] === $base['baseline_bytes'], 'repeated measurement never renews its baseline');
    $legacyStore = new FileSubmissionStore($root . '/legacy');
    (new QuotaSnapshot($legacyStore, $budgets))->remaining([]);
    $legacy = Files::readJson($legacyStore->directory() . '/quota-snapshot.json'); $legacy['schema'] = 1;
    foreach (['baseline_canonical_bytes', 'baseline_canonical_inodes', 'baseline_operational_bytes', 'baseline_operational_inodes', 'operational_namespace_id'] as $key) { unset($legacy[$key]); }
    Files::json($legacyStore->directory() . '/quota-snapshot.json', $legacy);
    $rejects(static fn() => (new QuotaSnapshot($legacyStore, $budgets, static fn(): array => $ops->measure()))->remaining([]),
        'legacy canonical-only baseline cannot silently grandfather existing release bytes');
    $unknown = new QuotaSnapshot($quotaRoot, [], static fn(): array => $ops->measure());
    $check($unknown->remaining([])['account_remaining_bytes'] === null, 'operational observations do not invent account quota');
    $rejects(static fn() => (new QuotaSnapshot($quotaRoot, $budgets, static fn(): array => ['complete' => false]))->remaining([]), 'incomplete supplemental snapshot holds admission');
    $otherOps = new OperationalStorage([$opPath, $root . '/future']);
    $rejects(static fn() => (new QuotaSnapshot($quotaRoot, $budgets, static fn(): array => $otherOps->measure()))->remaining([]), 'changing namespace cannot discard historical operational growth');
    $renewed = (new QuotaSnapshot($quotaRoot, array_replace($budgets, ['quota_observed_at' => 1700000100]), static fn(): array => $otherOps->measure()))->remaining([]);
    $check($renewed['account_remaining_bytes'] === 2000000, 'explicit fresh quota observation may establish new root baseline');

    $policyBudgets = $budgets + ['storage_bytes' => 10000000, 'storage_reserve_bytes' => 10000, 'update_reserve_bytes' => 1000000,
        'update_state_inodes' => 128, 'inode_reserve' => 5, 'file_bytes' => 1000000];
    $policy = new ResourcePolicy(['budgets' => $policyBudgets]); $policy->bindStore($store); $policy->setOperationalPaths([$state, $programs, $scratch]);
    $before = $policy->storageHeadroom($store->directory(), $store->usage());
    $check($before['operational_usage']['bytes'] === $measure->measure()['bytes'], 'policy headroom exposes operational scope without altering canonical allocation');
    $limits = $policy->updateStorageLimits($state); $stateUsage = (new OperationalStorage([$state], [$store->directory()], 1000, 2))->measure();
    $otherBytes = $measure->measure()['bytes'] - $stateUsage['bytes'];
    $check($limits['max_state_bytes'] === 1000000 - $otherBytes, 'state ceiling subtracts programs and PHP scratch from total update allocation');
    $check($limits['max_state_inodes'] === 128 && $limits['disk_reserve_bytes'] === 10000, 'state inode allocation and minimum disk free reserve retain distinct semantics');
    file_put_contents($state . '/generations/next', str_repeat('n', 50000));
    $after = $policy->storageHeadroom($store->directory(), $store->usage());
    $check($after['quota_snapshot']['account_remaining_bytes'] <= $before['quota_snapshot']['account_remaining_bytes'] - 50000, 'ordinary upload admission notices later database generations');
    $check($policy->diagnostics()['operational_storage']['complete'], 'diagnostics publish a complete bounded operational observation');
    $rejects(static fn() => $policy->updateStorageLimits($root . '/not-configured'), 'unaccounted update state cannot obtain resource allowance');
    foreach (['account_remaining_bytes', 'inodes_remaining', 'update_state_inodes', 'inode_reserve'] as $key) {
        $missing = $policyBudgets; unset($missing[$key]);
        $incomplete = new ResourcePolicy(['budgets' => $missing]);
        $missingStore = new FileSubmissionStore($root . '/missing-' . $key);
        $incomplete->bindStore($missingStore); $incomplete->setOperationalPaths([$state, $programs, $scratch]);
        $rejects(static fn() => $incomplete->updateStorageLimits($state), 'unknown update constraint ' . $key . ' holds automatic updates');
    }
    $tightBudgets = array_replace($policyBudgets, ['account_remaining_bytes' => 50000, 'inodes_remaining' => 8]);
    $tight = new ResourcePolicy(['budgets' => $tightBudgets]); $tightStore = new FileSubmissionStore($root . '/tight');
    $tight->bindStore($tightStore); $tight->setOperationalPaths([$state, $programs, $scratch]);
    $tightLimits = $tight->updateStorageLimits($state);
    $check($tightLimits['max_state_bytes'] === $tightLimits['state_usage']['bytes'] + 40000,
        'confirmed account bytes independently constrain absolute state ceiling after operating reserve');
    $check($tightLimits['max_state_inodes'] === $tightLimits['state_usage']['objects'] + 3,
        'confirmed remaining inodes constrain state ceiling after inode reserve');
    echo json_encode(['suite' => 'operational-storage', 'checks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
} finally { $remove($root); }

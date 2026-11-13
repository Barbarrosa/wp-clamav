<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'WPClamAV\\')) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});

use WPClamAV\Resources\WorkLedger;
use WPClamAV\Resources\QuotaSnapshot;
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
$root = sys_get_temp_dir() . '/wp-clamav-resource-ledgers-' . bin2hex(random_bytes(12));
mkdir($root, 0700);
$state = (object) ['now' => 1700000040];
$clock = static fn(): int => $state->now;
$budget = ['cpu_hourly_seconds' => 10, 'cpu_daily_seconds' => 20, 'cpu_monthly_seconds' => 30];
$stream = static function (string $bytes) { $stream = fopen('php://temp', 'w+b'); fwrite($stream, $bytes); rewind($stream); return $stream; };
try {
    $store = new FileSubmissionStore($root . '/private');
    $ledger = new WorkLedger($store, $budget, $clock);
    $check($ledger->remaining()['windows']['hourly']['remaining_seconds'] === 10.0, 'empty CPU allocation');
    $first = $ledger->reserve('scan:one', 4, 100);
    $check($ledger->remaining()['windows']['hourly']['used_seconds'] === 4.0, 'uncompleted reservation charged fully');
    $rejects(fn() => $ledger->reserve('scan:two', 7, 100), 'concurrent reservations cannot overspend hourly allocation');
    $ledger->complete($first, 1.25);
    $check($ledger->remaining()['windows']['hourly']['remaining_seconds'] === 8.75, 'measured actual CPU settles reservation');
    $ledger->complete($first, 0);
    $check($ledger->remaining()['windows']['hourly']['remaining_seconds'] === 8.75, 'repeated completion cannot refund an already settled job');
    $second = $ledger->reserve('scan:two', 8, 100); $ledger->complete($second, null);
    $check($ledger->remaining()['windows']['hourly']['used_seconds'] === 9.25, 'unknown actual CPU charges the reservation bound');
    $rejects(fn() => $ledger->reserve('scan:three', 1, 100), 'spent CPU remains charged');
    $state->now += 3600;
    $check($ledger->remaining()['windows']['hourly']['used_seconds'] === 9.25, 'overlapping minute is conservatively retained');
    $state->now += 60;
    $check($ledger->remaining()['windows']['hourly']['used_seconds'] === 0.0, 'hour allowance recovers after complete minute expiry');
    $check($ledger->remaining()['windows']['daily']['used_seconds'] === 9.25, 'daily accounting remains independent');
    $third = $ledger->reserve('update:abandoned', 2, 5);
    $state->now += 6;
    $report = $ledger->remaining();
    $check($report['pending_reservations'] === 0 && $report['conservative_settlements'] === 1, 'orphaned bounded job settles at its full reservation');
    $check($report['windows']['hourly']['used_seconds'] === 2.0, 'abandoned work is not silently refunded');
    $ledger->complete($third, 0);
    $check($ledger->remaining()['windows']['hourly']['used_seconds'] === 2.0, 'late completion cannot erase conservative crash charge');
    $store->commitSubmission('keep', ['narrative' => 'retained indefinitely']);
    $state->now += 2678400 + 120;
    $check($ledger->remaining()['windows']['monthly']['used_seconds'] === 0.0, 'operational counters expire after the longest window');
    $check(is_file($store->directory() . '/submissions/keep.json'), 'counter expiry never deletes submission history');
    $raw = Files::readJson($store->directory() . '/cpu-budget.json');
    $check($raw['packed_buckets'] === '' && $raw['reservations'] === [], 'expired operational state is compacted');
    $unknown = new WorkLedger($store, [], $clock);
    $check(count($unknown->remaining()['warnings']) === 3, 'unknown period allocations disclosed');
    $rejects(fn() => $unknown->reserve('scan:unknown', 1, 10), 'unknown budgets cannot authorize native work');
    $rejects(fn() => $ledger->reserve('scan:bad', INF, 10), 'nonfinite reservation rejected');

    $tinyDaily = new WorkLedger(new FileSubmissionStore($root . '/daily'),
        ['cpu_hourly_seconds' => 100, 'cpu_daily_seconds' => 1, 'cpu_monthly_seconds' => 1000], $clock);
    $rejects(fn() => $tinyDaily->reserve('scan:daily', 2, 10), 'daily allocation can be the binding constraint');

    if (function_exists('pcntl_fork')) {
        $concurrentStore = new FileSubmissionStore($root . '/concurrent');
        $children = [];
        for ($i = 0; $i < 2; ++$i) {
            $pid = pcntl_fork();
            if ($pid === -1) { throw new RuntimeException('Unable to fork reservation fixture.'); }
            if ($pid === 0) {
                try {
                    (new WorkLedger($concurrentStore, $budget, $clock))->reserve('scan:concurrent', 6, 100);
                    file_put_contents($root . '/result-' . $i, 'reserved');
                } catch (Throwable) { file_put_contents($root . '/result-' . $i, 'held'); }
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) { pcntl_waitpid($pid, $status); $check(pcntl_wexitstatus($status) === 0, 'reservation child completed'); }
        $outcomes = [file_get_contents($root . '/result-0'), file_get_contents($root . '/result-1')]; sort($outcomes);
        $check($outcomes === ['held', 'reserved'], 'atomic CPU ledger allows only one competing reservation');
        $check((new WorkLedger($concurrentStore, $budget, $clock))->remaining()['pending_reservations'] === 1, 'one durable reservation remains');
    }

    $quotaStore = new FileSubmissionStore($root . '/quotas');
    $confirmed = ['account_remaining_bytes' => 1000000, 'inodes_remaining' => 100, 'quota_observed_at' => 1700000040];
    $quota = new QuotaSnapshot($quotaStore, $confirmed);
    $baseline = $quota->remaining($quotaStore->usage());
    $check($baseline['account_remaining_bytes'] === 1000000, 'new confirmed observation establishes baseline once');
    $input = $stream(str_repeat('q', 20000));
    $receipt = $quotaStore->ingestStream($input, ['size' => 20000, 'name' => 'quota.bin']); fclose($input);
    $remaining = $quota->remaining($quotaStore->usage());
    $check($remaining['private_growth_bytes'] >= 20000, 'post-observation growth includes preserved bytes and metadata');
    $check($remaining['account_remaining_bytes'] === max(0, 1000000 - $remaining['private_growth_bytes']), 'remaining bytes cannot be reused for every new upload');
    $check($remaining['inodes_remaining'] === max(0, 100 - $remaining['private_growth_inodes']), 'inode growth reduces the confirmed remainder');
    $unrelated = new QuotaSnapshot($quotaStore, $confirmed + ['native_cpu_seconds' => 100]);
    $same = $unrelated->remaining($quotaStore->usage());
    $check($same['baseline_bytes'] === $baseline['baseline_bytes'], 'unrelated settings do not reset quota observations');
    $newObservation = new QuotaSnapshot($quotaStore, array_replace($confirmed, ['quota_observed_at' => 1700001000]));
    $refreshed = $newObservation->remaining($quotaStore->usage());
    $check($refreshed['account_remaining_bytes'] === 1000000 && $refreshed['baseline_bytes'] > $baseline['baseline_bytes'], 'explicit fresh observation can renew its baseline');
    $quotaStore->delete($receipt['id'], 1, 'Explicit resource-test deletion');
    $check($newObservation->remaining($quotaStore->usage())['account_remaining_bytes'] <= 1000000, 'deletion cannot invent more quota than the observation');
    $unknownQuota = new QuotaSnapshot($quotaStore, []);
    $check($unknownQuota->remaining($quotaStore->usage())['account_remaining_bytes'] === null, 'unknown account capacity is not unlimited');
    $rejects(fn() => (new QuotaSnapshot($quotaStore, ['account_remaining_bytes' => -1]))->remaining($quotaStore->usage()), 'invalid quota not accepted');
    $saved = Files::readJson($quotaStore->directory() . '/quota-snapshot.json'); $saved['identity'] = str_repeat('0', 64);
    Files::json($quotaStore->directory() . '/quota-snapshot.json', $saved);
    $rejects(fn() => $newObservation->remaining($quotaStore->usage()), 'corrupted observation cannot silently reset available quota');
    echo json_encode(['suite' => 'resource-ledgers', 'checks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname()); }
    @rmdir($root);
}

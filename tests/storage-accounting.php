<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'WPClamAV\\')) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});

use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\Files;
use WPClamAV\Storage\StorageException;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    ++$checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
};
$rejects = static function (callable $operation, string $message) use ($check): Throwable {
    try { $operation(); } catch (Throwable $error) { $check(true, $message); return $error; }
    $check(false, $message); throw new RuntimeException('unreachable');
};
$stream = static function (string $bytes) { $value = fopen('php://temp', 'w+b'); fwrite($value, $bytes); rewind($value); return $value; };
$root = sys_get_temp_dir() . '/wp-clamav-accounting-' . bin2hex(random_bytes(12));
mkdir($root, 0700);
$truth = static function (string $path): array {
    $result = ['logical_bytes' => 0, 'allocated_bytes' => 0, 'objects' => 0];
    $walk = static function (string $current) use (&$walk, &$result, $path): void {
        $relative = ltrim(substr($current, strlen($path)), '/');
        if (in_array($relative, ['scratch', 'programs', 'native-state'], true)) { return; }
        $stat = lstat($current);
        $result['logical_bytes'] += $stat['size'];
        if ($result['allocated_bytes'] !== null) {
            $result['allocated_bytes'] = isset($stat['blocks']) ? $result['allocated_bytes'] + $stat['blocks'] * 512 : null;
        }
        ++$result['objects'];
        if (is_dir($current)) {
            foreach (new DirectoryIterator($current) as $entry) { if (!$entry->isDot()) { $walk($entry->getPathname()); } }
        }
    };
    $walk($path); return $result;
};
$assertUsage = static function (FileSubmissionStore $store, string $message) use ($truth, $check): array {
    $usage = $store->usage(); $actual = $truth($store->directory());
    $check($usage['complete'] === true && $usage['schema'] === 2, $message . ': complete certified ledger');
    $check($usage['logical_bytes'] === $actual['logical_bytes'], $message . ': all logical bytes including metadata');
    $check($usage['allocated_bytes'] === $actual['allocated_bytes'], $message . ': allocated-byte units match stat blocks');
    $check($usage['objects'] === $actual['objects'], $message . ': all retained inodes counted');
    $check($usage['bytes'] === max($actual['logical_bytes'], $actual['allocated_bytes'] ?? 0), $message . ': conservative admission charge');
    return $usage;
};
try {
    $store = new FileSubmissionStore($root . '/private');
    $initial = $assertUsage($store, 'empty store');
    $check($initial['objects'] >= 8, 'control directories and accounting files count toward inodes');
    $input = $stream(str_repeat('x', 140000));
    $receipt = $store->ingestStream($input, ['name' => 'original.bin', 'size' => 140000]); fclose($input);
    $after = $assertUsage($store, 'received original');
    $check($after['logical_bytes'] > 140000, 'receipts and bookkeeping cannot hide behind payload-only totals');
    for ($i = 0; $i < 40; ++$i) {
        $store->appendEvent($receipt['id'], ['status' => 'held', 'completed' => false, 'reason_codes' => ['test'], 'note' => str_repeat('n', 200)]);
    }
    $eventsUsage = $assertUsage($store, 'event history');
    $check($eventsUsage['objects'] >= $after['objects'] + 40, 'indefinite immutable events consume capacity');
    $store->commitSubmission('form-1', ['narrative' => str_repeat('text ', 4000)], [$receipt['id']]);
    $assertUsage($store, 'canonical narrative');
    $second = new FileSubmissionStore($root . '/private');
    $second->commitSubmission('form-2', ['narrative' => 'second provider instance'], [$receipt['id']]);
    $assertUsage($store, 'two instances share a reentrant ledger');

    $large = $stream(str_repeat('y', 100));
    $error = $rejects(fn() => $store->ingestStream($large, ['name' => 'lying-length', 'size' => 1]), 'declared length cannot authorize extra writes'); fclose($large);
    $check($error instanceof StorageException && $error->receiptId !== null, 'oversized stream retains a failed receipt reference');
    $partial = $store->get($error->receiptId);
    $check(!$partial['complete'] && $partial['size'] === 0, 'excess first chunk is not written under a smaller reservation');
    $assertUsage($store, 'recovery after rejected stream');

    $rejects(fn() => $store->commitSubmission('too-large', ['narrative' => str_repeat('z', 1048576)]), 'metadata write/read size contracts agree');
    $check(!file_exists($store->directory() . '/submissions/too-large.json'), 'oversized metadata never becomes an unreadable canonical record');
    $assertUsage($store, 'recovery after metadata rejection');

    $badIndex = new class {
        public function upsert(array $receipt): void { throw new RuntimeException('fixture index unavailable'); }
        public function ids(int $limit, int $offset): array { return []; }
    };
    $indexed = new FileSubmissionStore($root . '/indexed', [], null, $badIndex);
    $received = $indexed->ingestStream($stream('index-independent original'), ['size' => 26, 'name' => 'kept.txt']);
    $check($received['complete'] && file_exists($indexed->directory() . '/.index-dirty.json'), 'advisory index failure does not destroy durable intake');
    $check(in_array($received['id'], array_column($indexed->list(), 'id'), true), 'failed index cannot hide canonical originals');
    $assertUsage($indexed, 'advisory index failure');

    if (function_exists('pcntl_fork')) {
        $beforeCrash = $store->usage();
        $pid = pcntl_fork();
        if ($pid === -1) { throw new RuntimeException('Could not fork crash fixture.'); }
        if ($pid === 0) {
            $crashingIndex = new class {
                public function upsert(array $receipt): void { exit(74); }
                public function ids(int $limit, int $offset): array { return []; }
            };
            $childStore = new FileSubmissionStore($root . '/private', [], null, $crashingIndex);
            $childStore->ingestStream($stream(str_repeat('c', 200000)), ['size' => 200000, 'name' => 'crash-preserved.bin']);
            exit(75);
        }
        pcntl_waitpid($pid, $status);
        $check(pcntl_wexitstatus($status) === 74, 'child stopped after object/receipt persistence but before accounting commit');
        $check(file_exists($store->directory() . '/.usage-dirty.json'), 'durable dirty marker survives abrupt process termination');
        $recovered = $assertUsage($store, 'post-crash reconciliation');
        $check($recovered['logical_bytes'] >= $beforeCrash['logical_bytes'] + 200000, 'crashed write cannot disappear from quota accounting');
        $check($recovered['index_repair_required'] === true, 'crash recovery marks the advisory index untrusted');
        $crashReceipts = array_filter($store->list(), static fn(array $row): bool => $row['name'] === 'crash-preserved.bin');
        $check(count($crashReceipts) === 1, 'canonical receipt survives even when index update never ran');
    }

    // A real bounded checkpoint resumes without deleting or reclassifying retained records.
    Files::json($store->directory() . '/.usage-dirty.json', ['fixture' => 'simulated interrupted write'], true);
    for ($i = 0; $i < 60; ++$i) {
        $path = $store->directory() . '/events/' . bin2hex(random_bytes(16)) . '.json';
        file_put_contents($path, '{"orphaned_original_event":true}'); chmod($path, 0600);
    }
    $error = $rejects(fn() => $store->reconcileUsage(16, 5), 'bounded reconciliation checkpoints large retained histories');
    $check(str_contains($error->getMessage(), 'checkpointed') && file_exists($store->directory() . '/.usage-reconcile.json'), 'checkpoint is durable and admission remains uncertified');
    $steps = 0;
    while (file_exists($store->directory() . '/.usage-dirty.json')) {
        if (++$steps > 30) { throw new RuntimeException('Reconciliation made no progress.'); }
        try { $store->reconcileUsage(16, 5); }
        catch (StorageException $error) {
            if (!str_contains($error->getMessage(), 'checkpointed')) { throw $error; }
        }
    }
    $check($steps > 1, 'recovery respects per-call entry budgets');
    $assertUsage($store, 'resumed reconciliation');

    // Interrupted accounting snapshots are disposable control state, unlike canonical .record files.
    $orphanControl = $store->directory() . '/.usage-record-' . bin2hex(random_bytes(16));
    file_put_contents($orphanControl, '{"partial_ledger":'); chmod($orphanControl, 0600);
    $orphanData = $store->directory() . '/.record-' . bin2hex(random_bytes(16));
    file_put_contents($orphanData, '{"preserve_submitter_record":true}'); chmod($orphanData, 0600);
    $assertUsage($store, 'interrupted accounting publication');
    $check(!file_exists($orphanControl) && file_exists($orphanData), 'recovery cleans only bookkeeping, never unknown submitter records');

    mkdir($store->directory() . '/programs', 0700);
    file_put_contents($store->directory() . '/programs/package', str_repeat('p', 500000));
    mkdir($store->directory() . '/native-state', 0700);
    file_put_contents($store->directory() . '/native-state/database', str_repeat('d', 500000));
    file_put_contents($store->scratchDirectory() . '/transient', str_repeat('t', 500000));
    $store->reconcileUsage(); $reserved = $assertUsage($store, 'program/database/scratch reserves');
    $check($reserved['separately_reserved'] === ['scratch', 'programs', 'native-state'], 'separate capacity domains are explicit');

    $old = $store->usage();
    $store->delete($receipt['id'], 1, 'Explicit administrator deletion fixture');
    $deleted = $assertUsage($store, 'explicit audited object deletion');
    $check($deleted['logical_bytes'] < $old['logical_bytes'] && file_exists($store->directory() . '/receipts/' . $receipt['id'] . '.json'), 'only explicit original deletion reduces retained object usage; metadata remains');
    echo json_encode(['suite' => 'storage-accounting', 'checks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname()); }
    @rmdir($root);
}

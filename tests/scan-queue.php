<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'WPClamAV\\')) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});
use WPClamAV\Scanning\DurableScanQueue;
use WPClamAV\Storage\FileSubmissionStore;
use WPClamAV\Storage\Files;
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { ++$checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $message); } };
$rejects = static function (callable $operation, string $message) use ($check): void {
    try { $operation(); } catch (Throwable) { $check(true, $message); return; }
    $check(false, $message);
};
$root = sys_get_temp_dir() . '/wp-clamav-queue-' . bin2hex(random_bytes(12)); mkdir($root, 0700);
try {
    $store = new FileSubmissionStore($root . '/private'); $queue = new DurableScanQueue($store); $now = 1700000000;
    $id = static fn(int $i): string => str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
    $check($queue->status($now)['pending'] === 0, 'empty bounded queue');
    $first = $queue->enqueue($id(1), 'full', false, null, 'submission-one', $now);
    $check($first['status'] === 'queued' && !$first['duplicate'], 'first public intent persists');
    $duplicate = $queue->enqueue($id(1), 'pdf-jpeg-png-pe-exclusions', false, null, 'replayed-other-submission', $now + 5);
    $check($duplicate['duplicate'] && $queue->status($now)['pending'] === 1, 'duplicate public references occupy one queue slot');
    $queue->enqueue($id(2), 'full', false, null, null, $now);
    $claim = $queue->claim($now, 20, 3); $request = $claim['request'];
    $check($request['profile'] === 'full' && $request['submission_id'] === 'submission-one', 'replay cannot replace original profile/submission proof');
    $check($request['attempts'] === 1, 'claim durably charges attempt before execution');
    $queue->finish($request, $now + 100, true);
    $check($queue->claim($now, 20, 3)['request'] === null, 'capacity refusal pauses dispatch');
    $queue->enqueue($id(1), 'full', false, null, 'submission-one', $now + 10);
    $queued = Files::readJson($store->directory() . '/scan-requests/' . $id(1) . '.json');
    $check($queued['attempts'] === 0 && $queued['next_at'] === $now + 100, 'public replay does not reset retry deadline and capacity refusal is not an attempt');
    $queue->enqueue($id(8), 'full', false, null, null, $now + 10);
    $check($queue->claim($now + 99, 20, 3)['request'] === null, 'new uploads cannot bypass shared pause');
    $next = $queue->claim($now + 100, 20, 3)['request'];
    $check($next['id'] === $id(2), 'cursor advances past deferred head for fair service');
    $queue->finish($next);
    $third = $queue->claim($now + 100, 20, 3)['request']; $queue->finish($third);
    $old = $queue->claim($now + 100, 20, 3)['request'];
    $queue->enqueue($id(1), 'full', true, 7, null, $now + 100);
    $queue->finish($old);
    $check($queue->status($now)['pending'] === 1, 'finishing an old revision cannot erase newer administrator intent');
    $explicit = $queue->claim($now + 100, 20, 3)['request'];
    $check($explicit['explicit'] && $explicit['actor'] === 7 && $explicit['profile'] === 'full', 'explicit full scan actor/profile persist');
    $audit = Files::readJson($store->directory() . '/audit/scan-request-' . $explicit['revision'] . '.json');
    $check($audit['action'] === 'explicit_scan_request' && $audit['actor'] === 7, 'administrator scan intent is retained canonically');
    $queue->finish($explicit);
    $check($queue->status($now)['pending'] === 0 && !file_exists($store->directory() . '/scan-requests/' . $id(1) . '.json'), 'completed operational marker retires without deleting its audit');
    $now += 100;
    $queue->enqueue($id(3), 'full', false, null, null, $now);
    $one = $queue->claim($now, 1, 2)['request']; // Simulate a worker crash before finish.
    $two = $queue->claim($now, 1, 2)['request'];
    $check($one['attempts'] === 1 && $two['attempts'] === 2, 'worker restart preserves earlier charged attempt');
    $queue->enqueue($id(3), 'full', false, null, null, $now);
    $exhausted = $queue->claim($now, 1, 2);
    $check($exhausted['exhausted'] && $exhausted['request']['attempts'] === 2, 'replay/restart cannot bypass execution attempt ceiling');
    $queue->finish($exhausted['request']);
    $rejects(fn() => $queue->enqueue($id(4), 'full', true, null, null, $now), 'explicit queue intent needs an actor');
    $rejects(fn() => $queue->enqueue('../outside', 'full', false, null, null, $now), 'queue paths are receipt IDs');

    // A journal can be the only completed write after a crash; its bounded index
    // recovers exactly one missing marker without enumerating any private data.
    $journal = $explicit; $journal['id'] = $id(5); $journal['explicit'] = false; $journal['actor'] = null;
    Files::json($store->directory() . '/scan-queue.json', ['schema' => 1, 'ids' => [$id(5)], 'cursor' => 0, 'not_before' => 0,
        'journal' => ['operation' => 'write', 'id' => $id(5), 'request' => $journal]]);
    $check($queue->status($now)['pending'] === 1 && file_exists($store->directory() . '/scan-requests/' . $id(5) . '.json'), 'interrupted index-first enqueue restores marker');
    Files::json($store->directory() . '/scan-queue.json', ['schema' => 1, 'ids' => [], 'cursor' => 0, 'not_before' => 0,
        'journal' => ['operation' => 'delete', 'id' => $id(5)]]);
    $check($queue->status($now)['pending'] === 0 && !file_exists($store->directory() . '/scan-requests/' . $id(5) . '.json'), 'interrupted marker retirement recovers only its dispatch metadata');
    $full = []; for ($i = 1; $i <= DurableScanQueue::MAX_REQUESTS; ++$i) { $full[] = $id($i); }
    Files::json($store->directory() . '/scan-queue.json', ['schema' => 1, 'ids' => $full, 'cursor' => 0, 'not_before' => 0, 'journal' => null]);
    $capacity = $queue->enqueue($id(9999), 'full', false, null, null, $now);
    $check($capacity['status'] === 'capacity' && !file_exists($store->directory() . '/scan-requests/' . $id(9999) . '.json'), 'full policy index never creates unindexed spill');
    $check(is_file($store->directory() . '/audit/scan-request-' . $explicit['revision'] . '.json'), 'capacity/recovery never purges canonical administrator audit');
    echo json_encode(['suite' => 'scan-queue', 'checks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname()); }
    @rmdir($root);
}

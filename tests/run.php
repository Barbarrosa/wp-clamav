<?php
declare(strict_types=1);
$scripts = ['core.php', 'storage-accounting.php', 'resource-ledgers.php', 'resource-dispatch.php', 'resource-constraints.php', 'network-allocations.php', 'operational-storage.php', 'scan-queue.php', 'scan-admission.php', 'notifications.php', 'updates.php', 'code-update-client.php', 'local-native.php', 'wordpress-adapters.php', 'plugin-updates-wordpress.php', 'application-wordpress.php'];
$failures = 0;
foreach ($scripts as $script) {
    $path = __DIR__ . '/' . $script;
    if (!is_file($path)) { continue; }
    $process = proc_open([PHP_BINARY, $path], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) { ++$failures; }
}
exit($failures ? 1 : 0);

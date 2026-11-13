<?php
declare(strict_types=1);
namespace WPClamAV\Scanning;

final class ProcessRunner
{
    public function run(array $arguments, array $limits, ?string $cwd = null): array
    {
        if (!function_exists('proc_open') || !function_exists('proc_terminate')) { throw new \RuntimeException('Native process execution is unavailable; configure remote scanning.'); }
        if (($limits['wall_seconds'] ?? 0) <= 0 || ($limits['output_bytes'] ?? 0) < 1) { throw new \RuntimeException('Native supervision limits are not configured.'); }
        foreach ($arguments as $argument) { if (!is_string($argument) || str_contains($argument, "\0")) { throw new \InvalidArgumentException('Invalid native argument.'); } }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // Never forward diagnostic hooks, injected libraries, update URLs or test overrides.
        $environment = ['LANG' => 'C.UTF-8', 'PATH' => '/usr/bin:/bin'];
        if ($cwd !== null) { $environment['TMPDIR'] = $cwd; }
        $process = proc_open($arguments, $descriptors, $pipes, $cwd, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new \RuntimeException('Could not start the native component.'); }
        $beforeUsage = function_exists('getrusage') ? getrusage(1) : false;
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $output = ''; $error = ''; $deadline = hrtime(true) / 1e9 + $limits['wall_seconds']; $exit = null;
        $pid = proc_get_status($process)['pid'];
        $completed = false;
        try {
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running'] && $status['exitcode'] >= 0) { $exit = $status['exitcode']; }
                $remaining = $deadline - hrtime(true) / 1e9;
                if ($remaining <= 0) { throw new \RuntimeException('Native work exceeded its calculated wall deadline.'); }
                $read = [];
                foreach ([1, 2] as $index) { if (!feof($pipes[$index])) { $read[] = $pipes[$index]; } }
                if ($read === []) { if (!$status['running']) { break; } usleep(10000); continue; }
                $write = null; $except = null;
                $ready = stream_select($read, $write, $except, 0, (int) min(100000, $remaining * 1000000));
                if ($ready === false) { throw new \RuntimeException('Native output supervision failed.'); }
                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 65536);
                    if ($chunk === false) { throw new \RuntimeException('Native output could not be read.'); }
                    if ($pipe === $pipes[1]) { $output .= $chunk; } else { $error .= $chunk; }
                    if (strlen($output) + strlen($error) > $limits['output_bytes']) { throw new \RuntimeException('Native protocol output exceeded its calculated limit.'); }
                }
            }
            $completed = true;
        } finally {
            $status = proc_get_status($process);
            if (!$completed || $status['running']) {
                if (function_exists('posix_kill')) { @posix_kill(-$pid, 9); }
                if ($status['running']) { proc_terminate($process, 9); }
            }
            fclose($pipes[1]); fclose($pipes[2]);
            $closed = proc_close($process);
            if ($exit === null) { $exit = $closed; }
        }
        $afterUsage = function_exists('getrusage') ? getrusage(1) : false;
        $seconds = static function (array $usage): float {
            return (float) ($usage['ru_utime.tv_sec'] ?? 0) + (float) ($usage['ru_stime.tv_sec'] ?? 0)
                + ((float) ($usage['ru_utime.tv_usec'] ?? 0) + (float) ($usage['ru_stime.tv_usec'] ?? 0)) / 1000000;
        };
        $cpu = is_array($beforeUsage) && is_array($afterUsage) ? max(0, $seconds($afterUsage) - $seconds($beforeUsage)) : null;
        return ['exit_code' => $exit, 'stdout' => $output, 'stderr' => $error, 'cpu_seconds' => $cpu];
    }

    public static function limitArguments(array $limits): array
    {
        return ['--as-bytes', (string) $limits['as_bytes'], '--cpu-seconds', (string) $limits['cpu_seconds'],
            '--wall-seconds', (string) max(1, (int) floor($limits['wall_seconds'])), '--file-bytes', (string) $limits['file_bytes']];
    }
}

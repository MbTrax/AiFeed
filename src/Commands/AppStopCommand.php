<?php

namespace App\Commands;

use App\Core\Command;

class AppStopCommand extends Command
{
    public function execute(array $args): void
    {
        $logger = $this->container->make('logger')->withChannel('supervisor');
        $config = (array)$this->container->make('config');
        $pidFile = (string)($config['run']['pidFile'] ?? (__DIR__ . '/../../storage/run/app.pids.json'));

        if (!is_file($pidFile)) {
            echo "[app:stop] pid file not found: {$pidFile}\n";
            $logger->warn('pid file not found', ['pidFile' => $pidFile]);
            return;
        }

        $raw = @file_get_contents($pidFile);
        $json = $raw ? json_decode($raw, true) : null;
        $list = is_array($json) ? ($json['pids'] ?? []) : [];

        if (!is_array($list) || !$list) {
            echo "[app:stop] no pids in pid file\n";
            $logger->warn('no pids in pid file', ['pidFile' => $pidFile]);
            return;
        }

        foreach ($list as $entry) {
            $pid = (int)($entry['pid'] ?? 0);
            $name = (string)($entry['name'] ?? 'proc');
            if ($pid <= 0) {
                continue;
            }

            $ok = $this->killPid($pid);
            $logger->info('kill', ['pid' => $pid, 'name' => $name, 'ok' => $ok]);
            echo "[app:stop] kill {$name} pid={$pid} ok=" . ($ok ? 'yes' : 'no') . "\n";
        }

        @unlink($pidFile);
        $logger->info('pid file removed', ['pidFile' => $pidFile]);
    }

    private function killPid(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'taskkill /PID ' . (int)$pid . ' /T /F';
            @exec($cmd, $out, $code);
            return $code === 0;
        }

        // Best-effort for Unix.
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, SIGTERM);
        }

        @exec('kill ' . (int)$pid, $out, $code);
        return $code === 0;
    }
}


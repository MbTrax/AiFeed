<?php

namespace App\Commands;

use App\Core\Command;

class AppStartCommand extends Command
{
    public function execute(array $args): void
    {
        $logger = $this->container->make('logger')->withChannel('supervisor');
        $config = (array)$this->container->make('config');
        $pidFile = (string)($config['run']['pidFile'] ?? (__DIR__ . '/../../storage/run/app.pids.json'));

        $php = PHP_BINARY;
        $console = realpath(__DIR__ . '/../Bin/Console.php');

        if (!$console) {
            echo "[app:start] Console.php not found\n";
            return;
        }

        echo "[app:start] Starting processes...\n";
        $logger->info('starting processes');

        $procs = [];
        $procs[] = $this->startProcess($php, $console, ['composer:start'], 'composer', $logger);
        $procs[] = $this->startProcess($php, $console, ['worker:start', 'tasks_similar'], 'worker:tasks_similar', $logger);
        $procs[] = $this->startProcess($php, $console, ['worker:start', 'tasks_large'], 'worker:tasks_large', $logger);
        // Embeddings are generated inside the main enrichment job on tasks_large.

        // Local web server for admin panel (public/).
        $web = (array)($config['web'] ?? []);
        $host = (string)($web['host'] ?? '127.0.0.1');
        $port = (int)($web['port'] ?? 8010);
        if ($port < 1) $port = 8010;
        $docroot = realpath(__DIR__ . '/../../public') ?: (__DIR__ . '/../../public');
        $procs[] = $this->startProcess($php, '', ['-S', "{$host}:{$port}", '-t', $docroot], "web:{$host}:{$port}", $logger);

        $this->writePidFile($pidFile, $procs, $logger);

        echo "[app:start] Running. Ctrl+C to stop this supervisor (child processes may keep running).\n";
        $logger->info('running');

        // Simple supervisor loop: keep parent alive and report if a child exits.
        while (true) {
            foreach ($procs as $entry) {
                if (!$entry) {
                    continue;
                }
                $this->drainOutput($entry, $logger);

                $status = proc_get_status($entry['proc']);
                if (!$status['running']) {
                    echo "[app:start] process exited: {$status['exitcode']}\n";
                    $logger->warn('process exited', ['name' => $entry['name'], 'exitCode' => $status['exitcode']]);
                }
            }
            sleep(2);
        }
    }

    private function startProcess(string $php, string $console, array $cmdArgs, string $name, $logger)
    {
        $cmd = escapeshellarg($php);
        if ($console !== '') {
            $cmd .= ' ' . escapeshellarg($console);
        }
        foreach ($cmdArgs as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }

        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cwd = $console !== '' ? dirname($console) : getcwd();
        $proc = proc_open($cmd, $spec, $pipes, $cwd);
        if (!is_resource($proc)) {
            echo "[app:start] failed to start: {$name}\n";
            $logger->error('failed to start', ['name' => $name]);
            return null;
        }

        // Don't block supervisor on child output.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        echo "[app:start] started: {$name}\n";
        $logger->info('started', ['name' => $name, 'cmd' => $cmd]);

        return [
            'name' => $name,
            'proc' => $proc,
            'pipes' => $pipes,
        ];
    }

    private function drainOutput(array $entry, $logger): void
    {
        $name = $entry['name'];
        $pipes = $entry['pipes'];

        $out = stream_get_contents($pipes[1]);
        if (is_string($out) && $out !== '') {
            foreach (preg_split("/\r?\n/", $out) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $logger->info($line, ['proc' => $name, 'stream' => 'stdout']);
            }
        }

        $err = stream_get_contents($pipes[2]);
        if (is_string($err) && $err !== '') {
            foreach (preg_split("/\r?\n/", $err) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $logger->error($line, ['proc' => $name, 'stream' => 'stderr']);
            }
        }
    }

    private function writePidFile(string $pidFile, array $procs, $logger): void
    {
        $dir = dirname($pidFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $data = [
            'createdAt' => date('c'),
            'pids' => [],
        ];

        foreach ($procs as $entry) {
            if (!$entry) continue;
            $st = proc_get_status($entry['proc']);
            $data['pids'][] = [
                'name' => $entry['name'],
                'pid' => $st['pid'] ?? null,
            ];
        }

        @file_put_contents($pidFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $logger->info('pid file written', ['pidFile' => $pidFile]);
    }
}

<?php

namespace App\Commands;

use App\Bin\Worker;
use App\Core\Command;
use Exception;

class WorkerStartCommand extends Command
{
    public function execute(array $args): void
    {
        $queueName = $args[0] ?? 'tasks_queue';
        $logger = $this->container->make('logger')->withChannel('worker');
        $logger->info('init', ['queue' => $queueName]);

        echo "[*] Starting worker for queue: {$queueName}\n";
        echo "[*] Ctrl+C to stop.\n";

        try {
            $worker = new Worker($this->container);
            $worker->run($queueName);
        } catch (Exception $e) {
            $logger->error($e->getMessage(), ['queue' => $queueName]);
            echo "[worker] error: " . $e->getMessage() . "\n";
        }
    }
}


<?php

namespace App\Commands;

use App\Bin\Worker;
use App\Core\Command;
use Exception;

class TestWorkerCommand extends Command
{
    public function execute(array $args): void
    {
        $queue = $args[0] ?? 'tasks_test_worker';
        $log = $this->container->make('logger')->withChannel('test');
        $log->info('test:worker start', ['queue' => $queue]);

        echo "[test:worker] queue={$queue}\n";

        try {
            $redis = $this->container->make('redis');
            $redis->push($queue, [
                'action' => 'ping',
                'msg' => 'test',
            ]);

            $worker = new Worker($this->container);
            $handled = $worker->runOnce($queue, 2);

            echo "[test:worker] handled=" . ($handled ? 'yes' : 'no') . "\n";
            $log->info('test:worker done', ['queue' => $queue, 'handled' => $handled]);
        } catch (Exception $e) {
            echo "[test:worker] FAIL {$e->getMessage()}\n";
            $log->error('test:worker fail: ' . $e->getMessage(), ['queue' => $queue]);
        }
    }
}

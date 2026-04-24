<?php

namespace App\Commands;

use App\Core\Command;
use App\Bin\Worker;
use Exception;

class WorkerStartCommand extends Command
{
    public function execute(array $args): void
    {
        $queueName = $args[0] ?? 'tasks_queue';
        echo "\e[32m[*] Запуск Воркера для очереди: $queueName...\e[0m\n";
        echo "[*] Нажми Ctrl+C для остановки.\n";
        try {
            $worker = new Worker($this->container);
            $worker->run($queueName);
        } catch (Exception $e) {
            echo "\e[31m[Ошибка Воркера]\e[0m " . $e->getMessage() . "\n";
        }
    }
}
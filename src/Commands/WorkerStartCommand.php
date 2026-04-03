<?php

namespace App\Commands;

use App\Core\Command;
use App\Bin\Worker;
use Exception;

class WorkerStartCommand extends Command
{
    public function execute(array $args): void
    {
        // Берем имя очереди из аргументов или ставим по умолчанию
        $queueName = $args[0] ?? 'tasks_queue';

        echo "\e[32m[*] Запуск Воркера для очереди: $queueName...\e[0m\n";
        echo "[*] Нажми Ctrl+C для остановки.\n";

        try {
            // Извлекаем все необходимые сервисы из контейнера
            $redis  = $this->container->make('redis');
            $db     = $this->container->make('db');
            $rss    = $this->container->make('rss');
            $parser = $this->container->make('parser');

            // Создаем объект Воркера, передавая все зависимости
            $worker = new Worker($redis, $db, $rss, $parser);

            // Запускаем бесконечный цикл обработки задач
            $worker->run($queueName);

        } catch (Exception $e) {
            echo "\e[31m[Ошибка Воркера]\e[0m " . $e->getMessage() . "\n";
        }
    }
}
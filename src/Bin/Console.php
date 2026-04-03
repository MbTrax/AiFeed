<?php
require_once __DIR__ . '/../../vendor/autoload.php';
$config = require __DIR__ . '/../../config/app.php';

use App\Core\Container;
use App\Core\Bootstrap;

$container = new Container();

Bootstrap::setup($container, $config);

// 2. Список доступных команд [название => класс]
$commands = [
    'rss:parse'      => \App\Commands\RssParseCommand::class,
    'worker:start'   => \App\Commands\WorkerStartCommand::class, // Твой основной воркер
    'composer:start' => \App\Commands\ComposerStartCommand::class, // Наш новый композитор
];

// 3. Разбор аргументов (php bin/console.php КОМАНДА АРГУМЕНТЫ)
$commandName = $argv[1] ?? null;
$arguments = array_slice($argv, 2);

if (!$commandName || !isset($commands[$commandName])) {
    echo "Доступные команды:\n";
    foreach (array_keys($commands) as $name) echo " - $name\n";
    exit;
}

// 4. Запуск команды через контейнер
$commandClass = $commands[$commandName];
$command = new $commandClass($container);
$command->execute($arguments);
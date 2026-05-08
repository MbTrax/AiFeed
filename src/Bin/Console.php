<?php
require_once __DIR__ . '/../../vendor/autoload.php';
$config = require __DIR__ . '/../../config/app.php';

use App\Core\Bootstrap;
use App\Core\Container;

$container = new Container();
Bootstrap::setup($container, $config);

// Command map: [name => class]
$commands = [
    'rss:parse' => \App\Commands\RssParseCommand::class,
    'worker:start' => \App\Commands\WorkerStartCommand::class,
    'composer:start' => \App\Commands\ComposerStartCommand::class,
    'app:start' => \App\Commands\AppStartCommand::class,
    'app:stop' => \App\Commands\AppStopCommand::class,
    'test:services' => \App\Commands\TestServicesCommand::class,
    'test:worker' => \App\Commands\TestWorkerCommand::class,
    'test:composer' => \App\Commands\TestComposerCommand::class,
    'test:ai' => \App\Commands\TestAiCommand::class,
];

$commandName = $argv[1] ?? null;
$arguments = array_slice($argv, 2);

if (!$commandName || !isset($commands[$commandName])) {
    echo "Available commands:\n";
    foreach (array_keys($commands) as $name) {
        echo " - {$name}\n";
    }
    exit(1);
}

$commandClass = $commands[$commandName];
$command = new $commandClass($container);
$command->execute($arguments);

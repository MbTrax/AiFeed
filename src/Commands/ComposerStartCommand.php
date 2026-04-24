<?php

namespace App\Commands;

use App\Core\Command;
use App\Bin\Composer;
use Exception;

class ComposerStartCommand extends Command
{
    public function execute(array $args): void
    {
        echo "\e[34m[*] Инициализация Композитора...\e[0m\n";
        try {
            $composer = new Composer($this->container);

            $composer->run();

        } catch (Exception $e) {
            echo "\e[31m[Критическая ошибка]\e[0m " . $e->getMessage() . "\n";
        }
    }
}
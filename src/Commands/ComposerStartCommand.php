<?php

namespace App\Commands;

use App\Bin\Composer;
use App\Core\Command;
use Exception;

class ComposerStartCommand extends Command
{
    public function execute(array $args): void
    {
        $logger = $this->container->make('logger')->withChannel('composer');
        $logger->info('init');

        echo "[*] Init composer...\n";
        try {
            $composer = new Composer($this->container);
            $composer->run();
        } catch (Exception $e) {
            $logger->error($e->getMessage());
            echo "[composer] error: " . $e->getMessage() . "\n";
        }
    }
}


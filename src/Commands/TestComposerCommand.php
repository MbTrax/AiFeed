<?php

namespace App\Commands;

use App\Bin\Composer;
use App\Core\Command;
use Exception;

class TestComposerCommand extends Command
{
    public function execute(array $args): void
    {
        $log = $this->container->make('logger')->withChannel('test');
        $log->info('test:composer start');

        echo "[test:composer] start\n";
        try {
            $composer = new Composer($this->container);
            $composer->runOnce();
            echo "[test:composer] OK\n";
            $log->info('test:composer ok');
        } catch (Exception $e) {
            echo "[test:composer] FAIL {$e->getMessage()}\n";
            $log->error('test:composer fail: ' . $e->getMessage());
        }
    }
}


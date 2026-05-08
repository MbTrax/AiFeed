<?php

namespace App\Commands;

use App\Core\Command;
use Exception;

class TestServicesCommand extends Command
{
    public function execute(array $args): void
    {
        $log = $this->container->make('logger')->withChannel('test');
        $config = (array)$this->container->make('config');

        echo "[test:services] start\n";
        $log->info('test:services start');

        // Redis
        try {
            $redis = $this->container->make('redis');
            $key = 'aifeed:test:' . uniqid('', true);
            $redis->set($key, 'ok', 10);
            $val = $redis->get($key);
            if ($val !== 'ok') {
                throw new Exception('redis set/get failed');
            }
            // sorted set support (delayed queue feature)
            $z = 'aifeed:test:z:' . uniqid('', true);
            $redis->zAdd($z, time(), 'ok');
            $items = $redis->zRangeByScore($z, 0, time() + 1, 10);
            if (!$items || $items[0] !== 'ok') {
                throw new Exception('redis zset failed');
            }
            echo "[test:services] redis: OK\n";
            $log->info('redis ok');
        } catch (Exception $e) {
            echo "[test:services] redis: FAIL {$e->getMessage()}\n";
            $log->error('redis fail: ' . $e->getMessage());
        }

        // DB
        try {
            $db = $this->container->make('db');
            $row = $db->fetchOne('SELECT 1 AS ok');
            if (!isset($row['ok']) || (int)$row['ok'] !== 1) {
                throw new Exception('db select failed');
            }
            echo "[test:services] db: OK\n";
            $log->info('db ok');
        } catch (Exception $e) {
            echo "[test:services] db: FAIL {$e->getMessage()}\n";
            $log->error('db fail: ' . $e->getMessage());
        }

        // RSS (optional)
        $urls = $config['rss']['urls'] ?? [];
        if (is_array($urls) && $urls) {
            $url = (string)$urls[0];
            try {
                $rss = $this->container->make('rss');
                $items = $rss->fetch($url);
                echo "[test:services] rss: OK items=" . count($items) . "\n";
                $log->info('rss ok', ['url' => $url, 'items' => count($items)]);
            } catch (Exception $e) {
                echo "[test:services] rss: FAIL {$e->getMessage()}\n";
                $log->error('rss fail: ' . $e->getMessage(), ['url' => $url]);
            }
        } else {
            echo "[test:services] rss: SKIP (RSS_URLS empty)\n";
            $log->info('rss skip');
        }

        echo "[test:services] done\n";
        $log->info('test:services done');
    }
}

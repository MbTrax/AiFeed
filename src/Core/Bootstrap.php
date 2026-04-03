<?php
namespace App\Core;

use App\Services\DatabaseService;
use App\Services\RedisService;

class Bootstrap {
    public static function setup(Container $container, array $config): void {
        $container->bind('redis', function() use ($config) {
            return new RedisService($config['redis']);
        });
        $container->bind('db', function() use ($config) {
            return new DatabaseService($config['db']);
        });
        $container->bind('rss', function() {
            return new \App\Services\RssService();
        });
        $container->bind('parser', function() {
            return new \App\Services\ParserService();
        });

    }
}
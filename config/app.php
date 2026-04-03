<?php
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
return [
    'redis' => [
        'host' => $_ENV['REDIS_HOST'] ?? 'Redis',
        'port' => $_ENV['REDIS_PORT'] ?? '5432',
        'prefix' => $_ENV['REDIS_PREFIX'] ?? '',
    ],
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'port' => $_ENV['DB_PORT'] ?? 'localhost',
        'name' => $_ENV['DB_NAME'] ?? 'localhost',
        'user' => $_ENV['DB_USER'] ?? 'root',
        'pass' => $_ENV['DB_PASS'] ?? '',
    ]
];
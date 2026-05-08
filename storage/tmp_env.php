<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createUnsafeMutable(dirname(__DIR__));
$dotenv->load();
echo "_ENV AI_HOST=" . ($_ENV['AI_HOST'] ?? 'null') . "\n";
echo "getenv AI_HOST=" . (getenv('AI_HOST') ?: 'null') . "\n";

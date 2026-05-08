<?php

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

// Use unsafe + mutable loader so values are available via getenv() and can override inherited OS env vars.
$dotenv = Dotenv\Dotenv::createUnsafeMutable(__DIR__);
$dotenv->load();

$env = static function (string $key, ?string $default = null): ?string {
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return $v;
    }
    return $default;
};

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'production' => [
            'adapter' => 'pgsql',
            'host' => $env('DB_HOST', '127.0.0.1'),
            'name' => $env('DB_NAME', ''),
            'user' => $env('DB_USER', ''),
            'pass' => $env('DB_PASS', ''),
            'port' => (int)($env('DB_PORT', '5432') ?? '5432'),
        ],
        'development' => [
            'adapter' => 'pgsql',
            'host' => $env('DB_HOST', '127.0.0.1'),
            'name' => $env('DB_NAME', ''),
            'user' => $env('DB_USER', ''),
            'pass' => $env('DB_PASS', ''),
            'port' => (int)($env('DB_PORT', '5432') ?? '5432'),
        ],
        'testing' => [
            'adapter' => 'pgsql',
            'host' => '127.0.0.1',
            'name' => 'testing_db',
            'user' => 'postgres',
            'pass' => '',
            'port' => 5432,
        ],
    ],
    'version_order' => 'creation',
];


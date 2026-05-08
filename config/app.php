<?php
// Use unsafe + mutable loader so values are available via getenv() and can override inherited OS env vars.
$dotenv = Dotenv\Dotenv::createUnsafeMutable(__DIR__ . '/../');
$dotenv->load();

$env = static function (string $key, ?string $default = null): ?string {
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return $v;
    }
    return $default;
};

$rssUrlsRaw = $env('RSS_URLS', '');
$rssUrls = array_values(array_filter(array_map('trim', preg_split('/[,\n;]/', (string)$rssUrlsRaw))));

$logFile = $env('LOG_FILE', '');
$defaultLogFile = __DIR__ . '/../storage/logs/app.log';
$logFilePath = $logFile !== '' ? $logFile : $defaultLogFile;

$pidFile = $env('PID_FILE', '');
$defaultPidFile = __DIR__ . '/../storage/run/app.pids.json';
$pidFilePath = $pidFile !== '' ? $pidFile : $defaultPidFile;

return [
    'redis' => [
        'host' => $env('REDIS_HOST', '127.0.0.1'),
        'port' => (int)($env('REDIS_PORT', '6379') ?? '6379'),
        'prefix' => $env('REDIS_PREFIX', ''),
    ],
    'db' => [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'port' => (int)($env('DB_PORT', '5432') ?? '5432'),
        'name' => $env('DB_NAME', ''),
        'user' => $env('DB_USER', ''),
        'pass' => $env('DB_PASS', ''),
    ],
    'rss' => [
        'urls' => $rssUrls,
        'pollIntervalSec' => (int)($env('RSS_POLL_INTERVAL', '300') ?? '300'),
    ],
    'log' => [
        'file' => $logFilePath,
    ],
    'run' => [
        'pidFile' => $pidFilePath,
    ],
    'aiService' => [
        'host' => $env('AI_HOST', 'localhost:1234'),
        // AiService historically expects apiUrl. Keep compatibility.
        'apiUrl' => (static function (?string $apiUrl, string $host): string {
            $apiUrl = trim((string)$apiUrl);
            if ($apiUrl !== '') {
                return $apiUrl;
            }
            $h = trim($host);
            if ($h === '') {
                $h = 'localhost:1234';
            }
            if (preg_match('/^https?:\\/\\//i', $h)) {
                return rtrim($h, '/') . '/v1/chat/completions';
            }
            return 'http://' . rtrim($h, '/') . '/v1/chat/completions';
        })($env('AI_API_URL', ''), (string)$env('AI_HOST', 'localhost:1234')),
        'embeddingsUrl' => (static function (?string $embUrl, string $host): string {
            $embUrl = trim((string)$embUrl);
            if ($embUrl !== '') {
                return $embUrl;
            }
            $h = trim($host);
            if ($h === '') {
                $h = 'localhost:1234';
            }
            if (preg_match('/^https?:\\/\\//i', $h)) {
                return rtrim($h, '/') . '/v1/embeddings';
            }
            return 'http://' . rtrim($h, '/') . '/v1/embeddings';
        })($env('AI_EMBEDDINGS_URL', ''), (string)$env('AI_HOST', 'localhost:1234')),
        'summariseModel' => $env('AI_SUMMARISE_MODEL', ''),
        'embeddingModel' => $env('AI_EMBEDDING_MODEL', ''),
    ]
];

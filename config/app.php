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
$rssStorageFile = __DIR__ . '/../storage/rss_urls.json';

// Merge RSS urls from env + storage file (admin panel writes here).
$rssStored = [];
if (is_file($rssStorageFile)) {
    $raw = @file_get_contents($rssStorageFile);
    if (is_string($raw) && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $rssStored = array_values(array_filter(array_map('trim', $json)));
        }
    }
}

$rssUrls = array_values(array_unique(array_filter(array_merge($rssUrls, $rssStored))));

$logFile = $env('LOG_FILE', '');
$defaultLogFile = __DIR__ . '/../storage/logs/app.log';
$logFilePath = $logFile !== '' ? $logFile : $defaultLogFile;

$pidFile = $env('PID_FILE', '');
$defaultPidFile = __DIR__ . '/../storage/run/app.pids.json';
$pidFilePath = $pidFile !== '' ? $pidFile : $defaultPidFile;

$webHost = (string)($env('WEB_HOST', '127.0.0.1') ?? '127.0.0.1');
$webPort = (int)($env('WEB_PORT', '8010') ?? '8010');
if ($webPort < 1) $webPort = 8010;

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
        'storageFile' => $rssStorageFile,
    ],
    'log' => [
        'file' => $logFilePath,
    ],
    'run' => [
        'pidFile' => $pidFilePath,
    ],
    'web' => [
        'host' => $webHost,
        'port' => $webPort,
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

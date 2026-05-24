<?php
require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/app.php';

use App\Core\Bootstrap;
use App\Core\Container;
use App\Core\Router;

$container = new Container();
Bootstrap::setup($container, $config);

$router = new Router($container);

// Admin SPA + API.
$router->add('/', \App\Controllers\AdminController::class, 'index');
$router->add('/admin', \App\Controllers\AdminController::class, 'index');
$router->add('/api/rss', \App\Controllers\AdminController::class, 'apiRss');
$router->add('/api/rss/add', \App\Controllers\AdminController::class, 'apiRssAdd');
$router->add('/api/queues', \App\Controllers\AdminController::class, 'apiQueues');
$router->add('/api/queues/peek', \App\Controllers\AdminController::class, 'apiQueuesPeek');
$router->add('/api/queues/clear', \App\Controllers\AdminController::class, 'apiQueuesClear');
$router->add('/api/logs', \App\Controllers\AdminController::class, 'apiLogs');

$router->add('/track.js', \App\Controllers\TrackingController::class, 'script');
$router->add('/api/track', \App\Controllers\TrackingController::class, 'collect');
$router->add('/api/track/login', \App\Controllers\TrackingController::class, 'loginMerge');

$uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($uri === '') $uri = '/';

echo $router->dispatch($uri);


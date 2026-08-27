<?php

declare(strict_types=1);

use N3\App\Controller\HomeController;
use N3\Core\Application;
use N3\Core\Http\Router;
use N3\Core\Logging\FileLogger;
use N3\Core\View\View;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

/** @var array{name: string, version: string, environment: string, debug: bool, timezone: string} $config */
$config = require $root . '/config/app.php';

date_default_timezone_set($config['timezone']);
ini_set('display_errors', '0');

set_error_handler(
    static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    },
);

$view = new View($root . '/resources/views');
$router = new Router();
$router->get('/', new HomeController($view, $config));

return new Application(
    router: $router,
    view: $view,
    logger: new FileLogger($root . '/storage/logs/app.log'),
    environment: $config['environment'],
);

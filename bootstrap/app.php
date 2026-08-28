<?php

declare(strict_types=1);

use N3\App\Controller\HomeController;
use N3\App\Identity\IdentityKernel;
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
$identityController = null;
$identity = static function () use (&$identityController, $root, $view, $config) {
    return $identityController ??= IdentityKernel::controller($root, $view, $config['environment']);
};
$router->get('/register', static fn ($request) => $identity()->showRegister($request));
$router->post('/register', static fn ($request) => $identity()->register($request));
$router->get('/verify-email', static fn ($request) => $identity()->showVerify($request));
$router->post('/verify-email', static fn ($request) => $identity()->verify($request));
$router->post('/verify-email/resend', static fn ($request) => $identity()->resend($request));
$accessController = null;
$access = static function () use (&$accessController, $root, $view, $config) {
    return $accessController ??= IdentityKernel::accessController($root, $view, $config['environment']);
};
$router->get('/login', static fn ($request) => $access()->showLogin($request));
$router->post('/login', static fn ($request) => $access()->login($request));
$router->post('/logout', static fn ($request) => $access()->logout($request));
$router->get('/account', static fn ($request) => $access()->account($request));
$router->get('/forgot-password', static fn ($request) => $access()->showForgot($request));
$router->post('/forgot-password', static fn ($request) => $access()->requestReset($request));
$router->get('/reset-password', static fn ($request) => $access()->showReset($request));
$router->post('/reset-password', static fn ($request) => $access()->reset($request));

return new Application(
    router: $router,
    view: $view,
    logger: new FileLogger($root . '/storage/logs/app.log'),
    environment: $config['environment'],
);

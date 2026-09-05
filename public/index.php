<?php

declare(strict_types=1);

use N3\App\Install\InstallationGate;
use N3\App\Install\InstallationLock;
use N3\App\Install\InstallerKernel;
use N3\Core\Http\Request;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
ini_set('display_errors', '0');
set_error_handler(
    static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    },
);
$request = Request::fromGlobals();
$lock = new InstallationLock($root . '/storage/install/installed.lock');
$gate = new InstallationGate($root, $lock);

if ($gate->shouldHandle($request)) {
    InstallerKernel::application($root, $request, $lock)->handle($request)->send();
}

/** @var N3\Core\Application $application */
$application = require $root . '/bootstrap/app.php';
$application->handle($request)->send();

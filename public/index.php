<?php

declare(strict_types=1);

use N3\Core\Http\Request;

/** @var N3\Core\Application $application */
$application = require dirname(__DIR__) . '/bootstrap/app.php';
$application->handle(Request::fromGlobals())->send();

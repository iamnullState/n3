<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use N3\Application;
use N3\Http\Request;

(new Application())->dispatch(Request::capture());

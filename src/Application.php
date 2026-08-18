<?php
declare(strict_types=1);

namespace N3;

use N3\Http\Request;

final class Application
{
    public function dispatch(Request $request): never
    {
        require __DIR__ . '/routes.php';
        exit;
    }
}


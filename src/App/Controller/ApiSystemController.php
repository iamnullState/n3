<?php

declare(strict_types=1);

namespace N3\App\Controller;

use N3\Core\Api\ApiResponder;
use N3\Core\Http\Request;
use N3\Core\Http\Response;

final readonly class ApiSystemController
{
    public function ping(Request $request): Response
    {
        return ApiResponder::success(['status' => 'ok'], (string) $request->attribute('request_id', ''));
    }
}

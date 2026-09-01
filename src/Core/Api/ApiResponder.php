<?php

declare(strict_types=1);

namespace N3\Core\Api;

use N3\Core\Http\Response;

final class ApiResponder
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, scalar|null> $meta
     */
    public static function success(array $data, string $requestId, array $meta = [], int $status = 200): Response
    {
        return Response::json([
            'data' => $data,
            'meta' => ['request_id' => $requestId, ...$meta],
        ], $status)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-N3-API-Version', '1');
    }

    /** @param array<string, scalar|list<string>|null> $details */
    public static function error(string $code, string $message, string $requestId, int $status, array $details = []): Response
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $code)) {
            throw new \InvalidArgumentException('API error codes must be stable lowercase identifiers.');
        }

        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return Response::json([
            'error' => $error,
            'meta' => ['request_id' => $requestId],
        ], $status)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-N3-API-Version', '1');
    }
}

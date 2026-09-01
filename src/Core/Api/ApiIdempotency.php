<?php

declare(strict_types=1);

namespace N3\Core\Api;

use N3\Core\Http\Request;

final class ApiIdempotency
{
    public static function requireKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key', '');
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/D', $key)) {
            throw new ApiRequestRejected('invalid_idempotency_key', 'A valid Idempotency-Key header is required.');
        }

        return $key;
    }

    public static function requestHash(Request $request): string
    {
        return hash('sha256', $request->method . "\n" . $request->path . "\n" . $request->rawBody());
    }
}

<?php
declare(strict_types=1);

namespace N3\Http;

final class JsonBody
{
    public static function decode(Request $request): array
    {
        $data = $request->json();
        if ($data === null) Response::json(['error' => 'Invalid JSON body.'], 400)->send();
        return $data;
    }
}

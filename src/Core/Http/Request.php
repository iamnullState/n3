<?php

declare(strict_types=1);

namespace N3\Core\Http;

final readonly class Request
{
    private function __construct(
        public string $method,
        public string $path,
    ) {
    }

    public static function fromGlobals(): self
    {
        return self::create(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
        );
    }

    public static function create(string $method, string $uri): self
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $normalizedPath = is_string($path) && $path !== '' ? $path : '/';
        $normalizedPath = '/' . ltrim($normalizedPath, '/');

        if ($normalizedPath !== '/') {
            $normalizedPath = rtrim($normalizedPath, '/');
        }

        return new self(strtoupper($method), $normalizedPath);
    }
}

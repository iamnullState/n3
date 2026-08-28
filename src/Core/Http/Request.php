<?php

declare(strict_types=1);

namespace N3\Core\Http;

final readonly class Request
{
    /**
     * @param array<string, scalar|array|null> $query
     * @param array<string, scalar|array|null> $body
     * @param array<string, scalar> $server
     * @param array<string, string> $cookies
     * @param array<string, mixed> $attributes
     */
    private function __construct(
        public string $method,
        public string $path,
        private array $query = [],
        private array $body = [],
        private array $server = [],
        private array $cookies = [],
        private array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        return self::create(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $_POST,
            $_SERVER,
            $_COOKIE,
        );
    }

    /**
     * @param array<string, scalar|array|null> $body
     * @param array<string, scalar> $server
     * @param array<string, string> $cookies
     */
    public static function create(
        string $method,
        string $uri,
        array $body = [],
        array $server = [],
        array $cookies = [],
    ): self
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $normalizedPath = is_string($path) && $path !== '' ? $path : '/';
        $normalizedPath = '/' . ltrim($normalizedPath, '/');

        if ($normalizedPath !== '/') {
            $normalizedPath = rtrim($normalizedPath, '/');
        }

        $query = [];
        $queryString = parse_url($uri, PHP_URL_QUERY);

        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        return new self(strtoupper($method), $normalizedPath, $query, $body, $server, $cookies);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function cookie(string $key, ?string $default = null): ?string
    {
        return $this->cookies[$key] ?? $default;
    }

    public function clientIp(): string
    {
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');

        return filter_var($ip, FILTER_VALIDATE_IP) === false ? '0.0.0.0' : $ip;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->server,
            $this->cookies,
            $attributes,
        );
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}

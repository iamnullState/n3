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
     * @param array<string, UploadedFile> $files
     */
    private function __construct(
        public string $method,
        public string $path,
        private array $query = [],
        private array $body = [],
        private array $server = [],
        private array $cookies = [],
        private array $attributes = [],
        private string $rawBody = '',
        private array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $rawBody = file_get_contents('php://input');

        return self::create(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $_POST,
            $_SERVER,
            $_COOKIE,
            $rawBody === false ? '' : $rawBody,
            self::normalizeFiles($_FILES),
        );
    }

    /**
     * @param array<string, scalar|array|null> $body
     * @param array<string, scalar> $server
     * @param array<string, string> $cookies
     * @param array<string, UploadedFile> $files
     */
    public static function create(
        string $method,
        string $uri,
        array $body = [],
        array $server = [],
        array $cookies = [],
        string $rawBody = '',
        array $files = [],
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

        return new self(strtoupper($method), $normalizedPath, $query, $body, $server, $cookies, rawBody: $rawBody, files: $files);
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

    public function header(string $name, ?string $default = null): ?string
    {
        $key = strtoupper(str_replace('-', '_', trim($name)));
        $value = $this->server['HTTP_' . $key] ?? $this->server[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function uploadedFile(string $key): ?UploadedFile
    {
        return $this->files[$key] ?? null;
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
            $this->rawBody,
            $this->files,
        );
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function routeParameter(string $key, ?string $default = null): ?string
    {
        $parameters = $this->attributes['route_parameters'] ?? [];

        return is_array($parameters) && isset($parameters[$key]) && is_string($parameters[$key])
            ? $parameters[$key]
            : $default;
    }

    /** @param array<string, mixed> $files @return array<string, UploadedFile> */
    private static function normalizeFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $key => $file) {
            if (!is_string($key) || !is_array($file)) {
                continue;
            }
            $uploaded = UploadedFile::fromGlobal($file);
            if ($uploaded !== null) {
                $normalized[$key] = $uploaded;
            }
        }

        return $normalized;
    }
}

<?php
declare(strict_types=1);

namespace N3\Http;

final class Request
{
    private array $query;
    private array $headers;
    private array $files;
    private string $body;
    private array $routeParameters = [];
    private bool $jsonDecoded = false;
    private ?array $json = null;

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        array $query = [],
        array $headers = [],
        string $body = '',
        array $files = [],
    ) {
        $this->query = $query;
        $this->headers = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || (!is_string($value) && !is_numeric($value))) continue;
            $normalized = strtolower(trim($name));
            if ($normalized !== '') $this->headers[$normalized] = trim((string)$value);
        }
        $this->body = $body;
        $this->files = $files;
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $body = file_get_contents('php://input');
        if (($body === '' || $body === false) && PHP_SAPI === 'cli' && defined('STDIN') && !stream_isatty(STDIN)) {
            $body = file_get_contents('php://stdin');
        }
        return new self(
            $method,
            $path,
            is_array($_GET) ? $_GET : [],
            self::captureHeaders(),
            $body === false ? '' : $body,
            is_array($_FILES) ? $_FILES : [],
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMutation(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    public function query(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->query) ? $this->query[$name] : $default;
    }

    public function queryParams(): array
    {
        return $this->query;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower(trim($name))] ?? $default;
    }

    public function file(string $name): ?array
    {
        $file = $this->files[$name] ?? null;
        if (!is_array($file)) return null;
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $field) {
            if (!array_key_exists($field, $file) || is_array($file[$field])) return null;
        }
        return [
            'name' => (string)$file['name'],
            'type' => (string)$file['type'],
            'tmp_name' => (string)$file['tmp_name'],
            'error' => (int)$file['error'],
            'size' => (int)$file['size'],
        ];
    }

    public function json(): ?array
    {
        if ($this->jsonDecoded) return $this->json;
        $this->jsonDecoded = true;
        if (trim($this->body) === '') return $this->json = [];
        try {
            $root = json_decode($this->body, false, 64, JSON_THROW_ON_ERROR);
            $decoded = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return $this->json = $root instanceof \stdClass && is_array($decoded) ? $decoded : null;
    }

    public function route(string $name, ?string $default = null): ?string
    {
        return $this->routeParameters[$name] ?? $default;
    }

    public function routeParams(): array
    {
        return $this->routeParameters;
    }

    public function withRouteParams(array $parameters): self
    {
        $request = clone $this;
        $request->routeParameters = $parameters;
        return $request;
    }

    private static function captureHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (!is_string($value)) continue;
            if (str_starts_with($name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
            } elseif ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $name))] = $value;
            }
        }
        return $headers;
    }
}

<?php

declare(strict_types=1);

namespace N3\Core\Http;

use JsonException;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string $body,
        private readonly int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** @param array<string, mixed> $body */
    public static function json(array $body, int $status = 200): self
    {
        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('JSON responses must contain encodable data.', previous: $exception);
        }

        return new self($json, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        if (!str_starts_with($location, '/')) {
            throw new \InvalidArgumentException('Redirect locations must be local paths.');
        }

        return new self('', $status, ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        $response = clone $this;
        $response->headers[$name] = $value;

        return $response;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): never
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
        exit;
    }
}

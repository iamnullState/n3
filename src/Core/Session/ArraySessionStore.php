<?php

declare(strict_types=1);

namespace N3\Core\Session;

final class ArraySessionStore implements SessionStore
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function regenerate(): void
    {
    }

    public function invalidate(): void
    {
        $this->values = [];
    }
}

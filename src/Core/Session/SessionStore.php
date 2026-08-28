<?php

declare(strict_types=1);

namespace N3\Core\Session;

interface SessionStore
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value): void;

    public function remove(string $key): void;

    public function regenerate(): void;

    public function invalidate(): void;
}

<?php

declare(strict_types=1);

namespace N3\Core\Service;

use LogicException;
use RuntimeException;

final class ServiceRegistry
{
    /** @var array<class-string|string, object> */
    private array $services = [];

    private bool $frozen = false;

    public function register(string $id, object $service): void
    {
        $id = trim($id);

        if ($this->frozen) {
            throw new LogicException('Services cannot be registered after module registration has completed.');
        }

        if ($id === '') {
            throw new LogicException('Service identifiers cannot be empty.');
        }

        if (isset($this->services[$id])) {
            throw new LogicException(sprintf('Service "%s" is already registered.', $id));
        }

        $this->services[$id] = $service;
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    public function get(string $id): object
    {
        return $this->services[$id]
            ?? throw new RuntimeException(sprintf('Service "%s" is not registered.', $id));
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}

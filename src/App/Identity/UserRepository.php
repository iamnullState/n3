<?php

declare(strict_types=1);

namespace N3\App\Identity;

interface UserRepository
{
    public function normalizedEmailExists(string $normalizedEmail): bool;

    public function createPending(
        string $displayName,
        string $email,
        string $normalizedEmail,
        string $passwordHash,
    ): int;
}

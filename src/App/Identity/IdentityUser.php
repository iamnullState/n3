<?php

declare(strict_types=1);

namespace N3\App\Identity;

final readonly class IdentityUser
{
    public function __construct(
        public int $id,
        public string $displayName,
        public string $email,
        public string $normalizedEmail,
        public string $passwordHash,
        public string $status,
        public string $role,
        public bool $emailVerified,
        public int $sessionVersion = 1,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace N3\App\Identity;

interface UserRepository
{
    public function normalizedEmailExists(string $normalizedEmail): bool;

    public function findByNormalizedEmail(string $normalizedEmail): ?IdentityUser;

    public function findById(int $userId): ?IdentityUser;

    public function createPending(
        string $displayName,
        string $email,
        string $normalizedEmail,
        string $passwordHash,
    ): int;

    public function markEmailVerified(int $userId): void;

    public function updatePasswordHash(int $userId, string $passwordHash, bool $invalidateSessions): void;

    public function recordSuccessfulLogin(int $userId): void;

    public function updateStatus(int $userId, string $status): void;

    public function updateAuthority(int $userId, string $authority): void;

    public function adminExists(): bool;

    public function createAdmin(string $displayName, string $email, string $normalizedEmail, string $passwordHash): int;
}

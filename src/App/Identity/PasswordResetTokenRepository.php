<?php

declare(strict_types=1);

namespace N3\App\Identity;

interface PasswordResetTokenRepository
{
    public function issue(int $userId, string $tokenHash, int $expiresAt): void;

    public function consume(string $tokenHash, int $now): ?int;

    public function revokeForUser(int $userId): void;

    public function prune(int $now): int;
}

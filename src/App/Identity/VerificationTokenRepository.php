<?php

declare(strict_types=1);

namespace N3\App\Identity;

interface VerificationTokenRepository
{
    public function issue(int $userId, string $tokenHash, int $expiresAt): void;

    public function consume(string $tokenHash, int $now): ?int;

    public function prune(int $now): int;
}

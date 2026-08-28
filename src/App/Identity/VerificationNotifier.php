<?php

declare(strict_types=1);

namespace N3\App\Identity;

interface VerificationNotifier
{
    public function sendVerification(string $email, string $displayName, string $url): void;

    public function prune(int $olderThanEpoch): int;
}

<?php

declare(strict_types=1);

namespace N3\App\Identity;

interface RateLimiter
{
    public function allow(string $action, string $subject, int $limit, int $windowSeconds): bool;

    public function prune(int $olderThanEpoch): int;
}

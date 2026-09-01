<?php

declare(strict_types=1);

namespace N3\Core\Api;

interface ApiRateLimiter
{
    public function allow(string $principalId, string $routeKey, int $limit, int $windowSeconds): bool;
}

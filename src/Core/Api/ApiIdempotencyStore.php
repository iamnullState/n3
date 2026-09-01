<?php

declare(strict_types=1);

namespace N3\Core\Api;

interface ApiIdempotencyStore
{
    public function begin(string $principalId, string $key, string $requestHash, int $ttlSeconds): string;

    /** @param array<string, mixed> $response */
    public function complete(string $principalId, string $key, int $status, array $response): void;
}

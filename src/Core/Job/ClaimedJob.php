<?php

declare(strict_types=1);

namespace N3\Core\Job;

final readonly class ClaimedJob
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $id,
        public string $moduleId,
        public string $type,
        public array $payload,
        public int $attempt,
        public int $maxAttempts,
        public string $leaseToken,
    ) {
    }
}

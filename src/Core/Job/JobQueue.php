<?php

declare(strict_types=1);

namespace N3\Core\Job;

use DateTimeImmutable;

interface JobQueue
{
    public function status(DateTimeImmutable $now): JobQueueStatus;

    /** @param array<string, mixed> $payload */
    public function enqueue(
        string $moduleId,
        string $type,
        array $payload,
        int $maxAttempts = 3,
        ?DateTimeImmutable $availableAt = null,
        ?string $idempotencyKey = null,
    ): int;

    public function claim(string $owner, DateTimeImmutable $now, int $leaseSeconds): ?ClaimedJob;

    public function succeed(ClaimedJob $job): void;

    public function retry(ClaimedJob $job, string $errorCode, DateTimeImmutable $availableAt): void;

    public function dead(ClaimedJob $job, string $errorCode): void;

    public function recoverExpired(DateTimeImmutable $now): JobRecoveryResult;

    public function retryDead(int $jobId, DateTimeImmutable $availableAt): bool;
}

<?php

declare(strict_types=1);

namespace N3\Core\Job;

final readonly class JobRunResult
{
    public function __construct(
        public string $status,
        public ?int $jobId = null,
        public ?string $errorCode = null,
    ) {
    }
}

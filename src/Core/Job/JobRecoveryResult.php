<?php

declare(strict_types=1);

namespace N3\Core\Job;

final readonly class JobRecoveryResult
{
    public function __construct(
        public int $requeued,
        public int $dead,
    ) {
    }
}

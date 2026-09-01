<?php

declare(strict_types=1);

namespace N3\Core\Job;

final readonly class JobQueueStatus
{
    public function __construct(
        public int $pending,
        public int $running,
        public int $succeeded,
        public int $dead,
        public int $expiredLeases,
    ) {
    }
}

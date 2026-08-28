<?php

declare(strict_types=1);

namespace N3\App\Identity;

interface SecurityEventRecorder
{
    public function record(
        string $event,
        string $outcome,
        string $subject,
        string $ip,
        ?int $userId,
        string $requestId,
    ): void;
}

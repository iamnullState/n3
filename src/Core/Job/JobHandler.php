<?php

declare(strict_types=1);

namespace N3\Core\Job;

interface JobHandler
{
    public function moduleId(): string;

    public function type(): string;

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void;
}

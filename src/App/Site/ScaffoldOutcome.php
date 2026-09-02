<?php

declare(strict_types=1);

namespace N3\App\Site;

final readonly class ScaffoldOutcome
{
    public function __construct(public int $createdPages, public int $existingPages, public bool $createdSettings)
    {
    }
}

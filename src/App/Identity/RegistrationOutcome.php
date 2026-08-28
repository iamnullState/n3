<?php

declare(strict_types=1);

namespace N3\App\Identity;

final readonly class RegistrationOutcome
{
    /** @param array<string, string> $errors */
    public function __construct(public array $errors = [], public bool $rateLimited = false)
    {
    }

    public function accepted(): bool
    {
        return $this->errors === [] && !$this->rateLimited;
    }
}

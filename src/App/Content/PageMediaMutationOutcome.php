<?php

declare(strict_types=1);

namespace N3\App\Content;

final readonly class PageMediaMutationOutcome
{
    /** @param array<string, string> $errors */
    public function __construct(public array $errors = [], public bool $conflict = false)
    {
    }

    public function succeeded(): bool
    {
        return $this->errors === [] && !$this->conflict;
    }
}

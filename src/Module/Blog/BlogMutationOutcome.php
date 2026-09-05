<?php

declare(strict_types=1);

namespace N3\Module\Blog;

final readonly class BlogMutationOutcome
{
    /** @param array<string, string> $errors */
    public function __construct(public array $errors = [], public ?int $postId = null, public bool $conflict = false)
    {
    }

    public function succeeded(): bool
    {
        return $this->errors === [] && !$this->conflict && $this->postId !== null;
    }
}

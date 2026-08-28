<?php

declare(strict_types=1);

namespace N3\App\Identity;

final readonly class AuthenticationOutcome
{
    public function __construct(
        public ?IdentityUser $user = null,
        public bool $verificationRequired = false,
    ) {
    }

    public function authenticated(): bool
    {
        return $this->user !== null;
    }
}

<?php

declare(strict_types=1);

namespace N3\App\Identity;

use N3\Core\Security\CurrentPrincipal;
use N3\Core\Security\CurrentPrincipalProvider;

final readonly class IdentityPrincipalProvider implements CurrentPrincipalProvider
{
    public function __construct(private AuthSessionManager $sessions)
    {
    }

    public function current(): ?CurrentPrincipal
    {
        $user = $this->sessions->current();

        return $user === null ? null : new CurrentPrincipal($user->role);
    }
}

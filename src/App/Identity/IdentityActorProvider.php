<?php

declare(strict_types=1);

namespace N3\App\Identity;

use N3\Core\Security\CurrentActor;
use N3\Core\Security\CurrentActorProvider;

final readonly class IdentityActorProvider implements CurrentActorProvider
{
    public function __construct(private AuthSessionManager $sessions)
    {
    }

    public function current(): ?CurrentActor
    {
        $user = $this->sessions->current();

        return $user === null ? null : new CurrentActor($user->id, $user->role);
    }
}

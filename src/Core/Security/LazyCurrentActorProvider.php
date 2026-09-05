<?php

declare(strict_types=1);

namespace N3\Core\Security;

use Closure;
use RuntimeException;

final class LazyCurrentActorProvider implements CurrentActorProvider
{
    private ?CurrentActorProvider $provider = null;

    /** @param Closure(): CurrentActorProvider $factory */
    public function __construct(private readonly Closure $factory)
    {
    }

    public function current(): ?CurrentActor
    {
        if ($this->provider === null) {
            $provider = ($this->factory)();
            if (!$provider instanceof CurrentActorProvider) {
                throw new RuntimeException('The current actor factory returned an invalid provider.');
            }
            $this->provider = $provider;
        }

        return $this->provider->current();
    }
}

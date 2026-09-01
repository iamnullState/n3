<?php

declare(strict_types=1);

namespace N3\Core\Security;

use Closure;
use RuntimeException;

final class LazyCurrentPrincipalProvider implements CurrentPrincipalProvider
{
    private ?CurrentPrincipalProvider $provider = null;

    /** @param Closure(): CurrentPrincipalProvider $factory */
    public function __construct(private readonly Closure $factory)
    {
    }

    public function current(): ?CurrentPrincipal
    {
        if ($this->provider === null) {
            $provider = ($this->factory)();
            if (!$provider instanceof CurrentPrincipalProvider) {
                throw new RuntimeException('The current principal factory returned an invalid provider.');
            }
            $this->provider = $provider;
        }

        return $this->provider->current();
    }
}

<?php

declare(strict_types=1);

namespace N3\Core\Security;

final readonly class CurrentPrincipal
{
    public function __construct(public string $authority)
    {
        if (!in_array($authority, ['admin', 'member'], true)) {
            throw new \InvalidArgumentException('Current principal authority must use the fixed authority vocabulary.');
        }
    }
}

<?php

declare(strict_types=1);

namespace N3\Core\Security;

final readonly class CurrentActor
{
    public function __construct(public int $id, public string $authority)
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Current actor identifiers must be positive integers.');
        }
        if (!in_array($authority, ['admin', 'member'], true)) {
            throw new \InvalidArgumentException('Current actor authority must use the fixed authority vocabulary.');
        }
    }
}

<?php

declare(strict_types=1);

namespace N3\Core\Security;

interface CurrentPrincipalProvider
{
    public function current(): ?CurrentPrincipal;
}

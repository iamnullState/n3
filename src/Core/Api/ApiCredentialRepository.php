<?php

declare(strict_types=1);

namespace N3\Core\Api;

interface ApiCredentialRepository
{
    public function findActiveByTokenHash(string $tokenHash): ?ApiPrincipal;
}

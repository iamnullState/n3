<?php

declare(strict_types=1);

namespace N3\Core\Api;

use N3\Core\Http\Request;

final readonly class HashedBearerAuthenticator implements ApiAuthenticator
{
    public function __construct(private ApiCredentialRepository $credentials)
    {
    }

    public function authenticate(Request $request): ?ApiPrincipal
    {
        $authorization = $request->header('Authorization', '');
        if (!is_string($authorization) || !preg_match('/^Bearer (n3_[A-Za-z0-9_-]{43,96})$/D', $authorization, $matches)) {
            return null;
        }

        return $this->credentials->findActiveByTokenHash(hash('sha256', $matches[1]));
    }
}

<?php

declare(strict_types=1);

namespace N3\Core\Api;

use N3\Core\Http\Request;

interface ApiAuthenticator
{
    public function authenticate(Request $request): ?ApiPrincipal;
}

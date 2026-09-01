<?php

declare(strict_types=1);

namespace N3\Core\Api;

final class ApiAccess
{
    public static function requireScope(?ApiPrincipal $principal, string $scope): ApiPrincipal
    {
        if ($principal === null) {
            throw new ApiRequestRejected('unauthenticated', 'Authentication is required.', 401);
        }
        if (!$principal->permits($scope)) {
            throw new ApiRequestRejected('forbidden', 'The authenticated client is not permitted to perform this action.', 403);
        }

        return $principal;
    }
}

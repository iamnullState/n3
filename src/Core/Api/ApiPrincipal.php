<?php

declare(strict_types=1);

namespace N3\Core\Api;

final readonly class ApiPrincipal
{
    /** @param list<string> $scopes */
    public function __construct(public string $id, public array $scopes)
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,99}$/D', $id)) {
            throw new \InvalidArgumentException('API principal IDs must be stable identifiers.');
        }
        foreach ($scopes as $scope) {
            if (!is_string($scope) || !preg_match('/^[a-z][a-z0-9._:-]{1,99}$/D', $scope)) {
                throw new \InvalidArgumentException('API scopes must be stable identifiers.');
            }
        }
    }

    public function permits(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}

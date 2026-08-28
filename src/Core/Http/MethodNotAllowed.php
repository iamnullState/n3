<?php

declare(strict_types=1);

namespace N3\Core\Http;

use RuntimeException;

final class MethodNotAllowed extends RuntimeException
{
    /** @param list<string> $allowed */
    public function __construct(public readonly array $allowed)
    {
        parent::__construct('Method not allowed.');
    }
}

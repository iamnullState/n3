<?php

declare(strict_types=1);

namespace N3\Core\Api;

use RuntimeException;

final class ApiRequestRejected extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $errorCode) || $status < 400 || $status > 499) {
            throw new \InvalidArgumentException('API request rejection metadata is invalid.');
        }
        parent::__construct($message);
    }
}

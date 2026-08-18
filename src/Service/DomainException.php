<?php
declare(strict_types=1);

namespace N3\Service;

use RuntimeException;

final class DomainException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}

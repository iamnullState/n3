<?php

declare(strict_types=1);

namespace N3\Core\Job;

use RuntimeException;
use InvalidArgumentException;

final class PermanentJobFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $errorCode)) {
            throw new InvalidArgumentException('Permanent job failures require a controlled error code.');
        }
        parent::__construct('The job handler rejected the job permanently.');
    }
}

<?php

declare(strict_types=1);

namespace N3\Core\Webhook;

use RuntimeException;

final class WebhookRejected extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('Webhook authentication failed.');
    }
}

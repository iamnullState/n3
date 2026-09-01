<?php

declare(strict_types=1);

namespace N3\Core\Webhook;

final readonly class WebhookTransportResult
{
    public function __construct(public int $status, public bool $retryable)
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('Webhook transport status is invalid.');
        }
    }
}

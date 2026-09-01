<?php

declare(strict_types=1);

namespace N3\Core\Webhook;

interface WebhookTransport
{
    /** @param array<string, string> $headers */
    public function send(string $url, array $headers, string $body): WebhookTransportResult;
}

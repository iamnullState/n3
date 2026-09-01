<?php

declare(strict_types=1);

namespace N3\Core\Webhook;

use DateTimeImmutable;

interface WebhookReplayStore
{
    public function consume(string $sourceKey, string $deliveryHash, DateTimeImmutable $expiresAt): bool;

    public function prune(DateTimeImmutable $before): int;
}

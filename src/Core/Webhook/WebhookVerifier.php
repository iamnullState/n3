<?php

declare(strict_types=1);

namespace N3\Core\Webhook;

use DateTimeImmutable;

final readonly class WebhookVerifier
{
    public function __construct(
        private WebhookReplayStore $replays,
        private int $maximumSkewSeconds = 300,
        private int $receiptTtlSeconds = 86400,
    ) {
        if ($maximumSkewSeconds < 1 || $maximumSkewSeconds > 900 || $receiptTtlSeconds < $maximumSkewSeconds) {
            throw new \InvalidArgumentException('Webhook verification timing configuration is invalid.');
        }
    }

    public function verify(
        string $sourceKey,
        string $body,
        string $deliveryId,
        string $timestamp,
        string $signature,
        string $secret,
        DateTimeImmutable $now,
    ): void {
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,99}$/D', $sourceKey)) {
            throw new WebhookRejected('invalid_source');
        }
        try {
            WebhookSigner::assertDeliveryId($deliveryId);
            WebhookSigner::assertSecret($secret);
        } catch (\InvalidArgumentException) {
            throw new WebhookRejected('invalid_signature');
        }
        if (!preg_match('/^[1-9][0-9]{0,11}$/D', $timestamp) || !preg_match('/^v1=([a-f0-9]{64})$/D', $signature, $matches)) {
            throw new WebhookRejected('invalid_signature');
        }

        $epoch = (int) $timestamp;
        if (abs($now->getTimestamp() - $epoch) > $this->maximumSkewSeconds) {
            throw new WebhookRejected('stale_timestamp');
        }

        $expected = hash_hmac('sha256', WebhookSigner::canonical($body, $deliveryId, $epoch), $secret);
        if (!hash_equals($expected, $matches[1])) {
            throw new WebhookRejected('invalid_signature');
        }

        $deliveryHash = hash('sha256', $deliveryId);
        if (!$this->replays->consume(
            $sourceKey,
            $deliveryHash,
            $now->modify(sprintf('+%d seconds', $this->receiptTtlSeconds)),
        )) {
            throw new WebhookRejected('replayed_delivery');
        }
    }
}

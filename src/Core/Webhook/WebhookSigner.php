<?php

declare(strict_types=1);

namespace N3\Core\Webhook;

use InvalidArgumentException;

final class WebhookSigner
{
    /** @return array{X-N3-Webhook-ID: string, X-N3-Webhook-Timestamp: string, X-N3-Webhook-Signature: string} */
    public static function headers(string $body, string $deliveryId, int $timestamp, string $secret): array
    {
        self::assertDeliveryId($deliveryId);
        self::assertSecret($secret);
        if ($timestamp < 1) {
            throw new InvalidArgumentException('Webhook timestamps must be positive Unix timestamps.');
        }

        return [
            'X-N3-Webhook-ID' => $deliveryId,
            'X-N3-Webhook-Timestamp' => (string) $timestamp,
            'X-N3-Webhook-Signature' => 'v1=' . hash_hmac('sha256', self::canonical($body, $deliveryId, $timestamp), $secret),
        ];
    }

    public static function canonical(string $body, string $deliveryId, int $timestamp): string
    {
        return $timestamp . '.' . $deliveryId . '.' . $body;
    }

    public static function assertDeliveryId(string $deliveryId): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/D', $deliveryId)) {
            throw new InvalidArgumentException('Webhook delivery IDs must be stable 16–100 character identifiers.');
        }
    }

    public static function assertSecret(string $secret): void
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('Webhook signing secrets must contain at least 32 bytes.');
        }
    }
}

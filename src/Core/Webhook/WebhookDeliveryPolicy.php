<?php

declare(strict_types=1);

namespace N3\Core\Webhook;

final class WebhookDeliveryPolicy
{
    public static function isSuccessful(int $status): bool
    {
        self::assertValidStatus($status);

        return $status >= 200 && $status <= 299;
    }

    public static function isRetryable(int $status): bool
    {
        self::assertValidStatus($status);

        return in_array($status, [408, 425, 429], true) || $status >= 500;
    }

    private static function assertValidStatus(int $status): void
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('Webhook HTTP status must be between 100 and 599.');
        }
    }
}

<?php

declare(strict_types=1);

namespace N3\Core\Webhook;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use InvalidArgumentException;

final readonly class PdoWebhookReplayStore implements WebhookReplayStore
{
    public function __construct(private PDO $connection)
    {
    }

    public function consume(string $sourceKey, string $deliveryHash, DateTimeImmutable $expiresAt): bool
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,99}$/D', $sourceKey)
            || !preg_match('/^[a-f0-9]{64}$/D', $deliveryHash)) {
            throw new InvalidArgumentException('Webhook replay identifiers are invalid.');
        }
        try {
            $statement = $this->connection->prepare(
                'INSERT INTO webhook_receipts (source_key, delivery_hash, expires_at) '
                . 'VALUES (:source_key, :delivery_hash, :expires_at)',
            );
            $statement->execute([
                'source_key' => $sourceKey,
                'delivery_hash' => $deliveryHash,
                'expires_at' => self::date($expiresAt),
            ]);
            return true;
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return false;
            }
            throw $exception;
        }
    }

    public function prune(DateTimeImmutable $before): int
    {
        $statement = $this->connection->prepare('DELETE FROM webhook_receipts WHERE expires_at < :before');
        $statement->execute(['before' => self::date($before)]);

        return $statement->rowCount();
    }

    private static function date(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}

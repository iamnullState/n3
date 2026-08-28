<?php

declare(strict_types=1);

namespace N3\App\Identity;

use PDO;

final readonly class PdoVerificationTokenRepository implements VerificationTokenRepository
{
    public function __construct(private PDO $connection)
    {
    }

    public function issue(int $userId, string $tokenHash, int $expiresAt): void
    {
        $this->connection->prepare(
            'UPDATE email_verification_tokens SET revoked_at = CURRENT_TIMESTAMP(6) '
            . 'WHERE user_id = :user_id AND consumed_at IS NULL AND revoked_at IS NULL',
        )->execute(['user_id' => $userId]);
        $this->connection->prepare(
            'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) '
            . 'VALUES (:user_id, :token_hash, FROM_UNIXTIME(:expires_at))',
        )->execute(['user_id' => $userId, 'token_hash' => $tokenHash, 'expires_at' => $expiresAt]);
    }

    public function consume(string $tokenHash, int $now): ?int
    {
        $started = !$this->connection->inTransaction();

        if ($started) {
            $this->connection->beginTransaction();
        }

        try {
            $statement = $this->connection->prepare(
                'SELECT id, user_id FROM email_verification_tokens '
                . 'WHERE token_hash = :token_hash AND consumed_at IS NULL AND revoked_at IS NULL '
                . 'AND expires_at >= FROM_UNIXTIME(:now) FOR UPDATE',
            );
            $statement->execute(['token_hash' => $tokenHash, 'now' => $now]);
            $record = $statement->fetch();

            if (!is_array($record)) {
                if ($started) {
                    $this->connection->commit();
                }
                return null;
            }

            $this->connection->prepare(
                'UPDATE email_verification_tokens SET consumed_at = CURRENT_TIMESTAMP(6) WHERE id = :id',
            )->execute(['id' => $record['id']]);

            if ($started) {
                $this->connection->commit();
            }

            return (int) $record['user_id'];
        } catch (\Throwable $exception) {
            if ($started && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function prune(int $now): int
    {
        $statement = $this->connection->prepare(
            'DELETE FROM email_verification_tokens WHERE expires_at < FROM_UNIXTIME(:now) '
            . 'OR consumed_at IS NOT NULL OR revoked_at IS NOT NULL',
        );
        $statement->execute(['now' => $now]);

        return $statement->rowCount();
    }
}

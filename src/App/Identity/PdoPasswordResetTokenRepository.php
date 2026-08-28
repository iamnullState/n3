<?php

declare(strict_types=1);

namespace N3\App\Identity;

use PDO;

final readonly class PdoPasswordResetTokenRepository implements PasswordResetTokenRepository
{
    public function __construct(private PDO $connection)
    {
    }

    public function issue(int $userId, string $tokenHash, int $expiresAt): void
    {
        $this->revokeForUser($userId);
        $statement = $this->connection->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) '
            . 'VALUES (:user_id, :token_hash, FROM_UNIXTIME(:expires_at))',
        );
        $statement->execute(['user_id' => $userId, 'token_hash' => $tokenHash, 'expires_at' => $expiresAt]);
    }

    public function consume(string $tokenHash, int $now): ?int
    {
        $statement = $this->connection->prepare(
            'SELECT id, user_id FROM password_reset_tokens '
            . 'WHERE token_hash = :token_hash AND consumed_at IS NULL AND revoked_at IS NULL '
            . 'AND expires_at >= FROM_UNIXTIME(:now) FOR UPDATE',
        );
        $statement->execute(['token_hash' => $tokenHash, 'now' => $now]);
        $record = $statement->fetch();
        if (!is_array($record)) {
            return null;
        }
        $this->connection->prepare(
            'UPDATE password_reset_tokens SET consumed_at = CURRENT_TIMESTAMP(6) WHERE id = :id',
        )->execute(['id' => $record['id']]);

        return (int) $record['user_id'];
    }

    public function revokeForUser(int $userId): void
    {
        $this->connection->prepare(
            'UPDATE password_reset_tokens SET revoked_at = CURRENT_TIMESTAMP(6) '
            . 'WHERE user_id = :user_id AND consumed_at IS NULL AND revoked_at IS NULL',
        )->execute(['user_id' => $userId]);
    }

    public function prune(int $now): int
    {
        $statement = $this->connection->prepare(
            'DELETE FROM password_reset_tokens WHERE expires_at < FROM_UNIXTIME(:now) '
            . 'OR consumed_at IS NOT NULL OR revoked_at IS NOT NULL',
        );
        $statement->execute(['now' => $now]);

        return $statement->rowCount();
    }
}

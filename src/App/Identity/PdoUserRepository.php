<?php

declare(strict_types=1);

namespace N3\App\Identity;

use PDO;
use RuntimeException;

final readonly class PdoUserRepository implements UserRepository
{
    public function __construct(private PDO $connection)
    {
    }

    public function normalizedEmailExists(string $normalizedEmail): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM users WHERE email_normalized = :email LIMIT 1',
        );
        $statement->execute(['email' => $normalizedEmail]);

        return $statement->fetchColumn() !== false;
    }

    public function findByNormalizedEmail(string $normalizedEmail): ?IdentityUser
    {
        $statement = $this->connection->prepare(
            'SELECT id, display_name, email, email_normalized, password_hash, account_status, role_key, '
            . 'email_verified_at, 1 AS session_version FROM users WHERE email_normalized = :email LIMIT 1',
        );
        $statement->execute(['email' => $normalizedEmail]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return new IdentityUser(
            (int) $row['id'],
            (string) $row['display_name'],
            (string) $row['email'],
            (string) $row['email_normalized'],
            (string) $row['password_hash'],
            (string) $row['account_status'],
            (string) $row['role_key'],
            $row['email_verified_at'] !== null,
            (int) $row['session_version'],
        );
    }

    public function createPending(
        string $displayName,
        string $email,
        string $normalizedEmail,
        string $passwordHash,
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO users '
            . '(display_name, email, email_normalized, password_hash, account_status, role_key) '
            . 'VALUES (:display_name, :email, :email_normalized, :password_hash, :account_status, :role_key)',
        );
        $statement->execute([
            'display_name' => $displayName,
            'email' => $email,
            'email_normalized' => $normalizedEmail,
            'password_hash' => $passwordHash,
            'account_status' => 'pending_verification',
            'role_key' => 'member',
        ]);

        $id = $this->connection->lastInsertId();

        if (!ctype_digit($id)) {
            throw new RuntimeException('MariaDB did not return a valid user identifier.');
        }

        return (int) $id;
    }

    public function markEmailVerified(int $userId): void
    {
        $statement = $this->connection->prepare(
            "UPDATE users SET account_status = 'active', email_verified_at = CURRENT_TIMESTAMP(6) "
            . "WHERE id = :id AND account_status = 'pending_verification'",
        );
        $statement->execute(['id' => $userId]);
    }
}

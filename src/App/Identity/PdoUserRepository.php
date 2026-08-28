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
            . 'email_verified_at, session_version FROM users WHERE email_normalized = :email LIMIT 1',
        );
        $statement->execute(['email' => $normalizedEmail]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findById(int $userId): ?IdentityUser
    {
        $statement = $this->connection->prepare(
            'SELECT id, display_name, email, email_normalized, password_hash, account_status, role_key, '
            . 'email_verified_at, session_version FROM users WHERE id = :id LIMIT 1',
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): IdentityUser
    {
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

    public function updatePasswordHash(int $userId, string $passwordHash, bool $invalidateSessions): void
    {
        $sql = 'UPDATE users SET password_hash = :password_hash';
        if ($invalidateSessions) {
            $sql .= ', session_version = session_version + 1';
        }
        $statement = $this->connection->prepare($sql . ' WHERE id = :id');
        $statement->execute(['password_hash' => $passwordHash, 'id' => $userId]);
    }

    public function recordSuccessfulLogin(int $userId): void
    {
        $this->connection->prepare(
            'UPDATE users SET last_login_at = CURRENT_TIMESTAMP(6) WHERE id = :id',
        )->execute(['id' => $userId]);
    }

    public function updateStatus(int $userId, string $status): void
    {
        if (!in_array($status, ['pending_verification', 'active', 'disabled'], true)) {
            throw new \InvalidArgumentException('Unsupported account status.');
        }
        $this->connection->prepare(
            'UPDATE users SET account_status = :status, session_version = session_version + 1 WHERE id = :id',
        )->execute(['status' => $status, 'id' => $userId]);
    }

    public function updateAuthority(int $userId, string $authority): void
    {
        if (!in_array($authority, ['admin', 'member'], true)) {
            throw new \InvalidArgumentException('Unsupported account authority.');
        }
        $this->connection->prepare(
            'UPDATE users SET role_key = :authority, session_version = session_version + 1 WHERE id = :id',
        )->execute(['authority' => $authority, 'id' => $userId]);
    }

    public function adminExists(): bool
    {
        $suffix = $this->connection->inTransaction() ? ' FOR UPDATE' : '';
        return $this->connection->query("SELECT 1 FROM users WHERE role_key = 'admin' LIMIT 1" . $suffix)->fetchColumn() !== false;
    }

    public function createAdmin(string $displayName, string $email, string $normalizedEmail, string $passwordHash): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO users (display_name, email, email_normalized, password_hash, account_status, role_key, email_verified_at) '
            . "VALUES (:display_name, :email, :email_normalized, :password_hash, 'active', 'admin', CURRENT_TIMESTAMP(6))",
        );
        $statement->execute([
            'display_name' => $displayName,
            'email' => $email,
            'email_normalized' => $normalizedEmail,
            'password_hash' => $passwordHash,
        ]);
        $id = $this->connection->lastInsertId();
        if (!ctype_digit($id)) {
            throw new RuntimeException('MariaDB did not return a valid user identifier.');
        }

        return (int) $id;
    }
}

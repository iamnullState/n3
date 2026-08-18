<?php
declare(strict_types=1);

namespace N3\Service;

use PDO;
use PDOException;

final class AccountService
{
    public function __construct(private readonly PDO $database) {}

    public function passwordHash(int $userId): string
    {
        $statement = $this->database->prepare('SELECT password_hash FROM users WHERE id = ?');
        $statement->execute([$userId]);
        return (string)$statement->fetchColumn();
    }

    public function update(int $userId, string $username, string $passwordHash, int $sessionVersion): void
    {
        $statement = $this->database->prepare('UPDATE users SET username = ?, password_hash = ?, session_version = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $statement->execute([$username, $passwordHash, $sessionVersion, $userId]);
    }

    public function updateSessionVersion(int $userId, int $sessionVersion): void
    {
        $statement = $this->database->prepare('UPDATE users SET session_version = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $statement->execute([$sessionVersion, $userId]);
    }

    public function changeCredentials(
        int $userId,
        int $sessionVersion,
        string $currentPassword,
        mixed $username,
        string $newPassword,
    ): array {
        $hash = $this->passwordHash($userId);
        if (!password_verify($currentPassword, $hash)) {
            throw new DomainException('Current password is incorrect.', 403);
        }
        $normalizedUsername = mb_substr(trim((string)$username), 0, 80);
        if ($normalizedUsername === '') throw new DomainException('Username is required.', 422);
        if ($newPassword !== '' && mb_strlen($newPassword) < 12) {
            throw new DomainException('Use at least 12 characters for the new password.', 422);
        }

        $nextVersion = $sessionVersion + 1;
        $nextHash = $newPassword === '' ? $hash : password_hash($newPassword, PASSWORD_DEFAULT);
        try {
            $this->update($userId, $normalizedUsername, $nextHash, $nextVersion);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') throw new DomainException('That username is unavailable.', 409);
            throw $error;
        }
        return ['username' => $normalizedUsername, 'session_version' => $nextVersion];
    }

    public function invalidateOtherSessions(int $userId, int $sessionVersion, string $currentPassword): int
    {
        if (!password_verify($currentPassword, $this->passwordHash($userId))) {
            throw new DomainException('Current password is incorrect.', 403);
        }
        $nextVersion = $sessionVersion + 1;
        $this->updateSessionVersion($userId, $nextVersion);
        return $nextVersion;
    }
}

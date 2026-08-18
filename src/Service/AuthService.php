<?php
declare(strict_types=1);

namespace N3\Service;

use PDO;

final class AuthService
{
    public function __construct(private readonly PDO $database) {}

    public function accountExists(): bool
    {
        return (int)$this->database->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }

    public function createOwner(string $username, string $password): ?int
    {
        $this->database->beginTransaction();
        try {
            $adminColumn = $this->columnExists('users', 'is_admin');
            $sql = $adminColumn
                ? 'INSERT INTO users (username, password_hash, is_admin) SELECT ?, ?, 1 WHERE NOT EXISTS (SELECT 1 FROM users)'
                : 'INSERT INTO users (username, password_hash) SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM users)';
            $statement = $this->database->prepare($sql);
            $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            if ($statement->rowCount() !== 1) {
                $this->database->rollBack();
                return null;
            }
            $id = (int)$this->database->lastInsertId();
            $this->initializeProfile($id, $username);
            if ($this->columnExists('spaces', 'owner_id')) {
                $this->database->prepare('UPDATE spaces SET owner_id = ? WHERE owner_id IS NULL')->execute([$id]);
                if ($this->columnExists('pages', 'author_id') && $this->columnExists('pages', 'last_editor_id')) {
                    $this->database->prepare(<<<'SQL'
                        UPDATE pages
                        SET author_id = COALESCE(author_id, ?), last_editor_id = COALESCE(last_editor_id, ?)
                        WHERE space_id IN (SELECT id FROM spaces WHERE owner_id = ?)
                    SQL)->execute([$id, $id, $id]);
                }
            }
            $this->database->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    public function createCollaborator(string $username, string $password): int
    {
        $this->database->beginTransaction();
        try {
            $adminColumn = $this->columnExists('users', 'is_admin');
            $sql = $adminColumn
                ? 'INSERT INTO users (username, password_hash, is_admin) VALUES (?, ?, 0)'
                : 'INSERT INTO users (username, password_hash) VALUES (?, ?)';
            $statement = $this->database->prepare($sql);
            $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            $id = (int)$this->database->lastInsertId();
            $this->initializeProfile($id, $username);
            $this->database->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    public function findByUsername(string $username): ?array
    {
        $admin = $this->columnExists('users', 'is_admin') ? ', is_admin' : ', 0 AS is_admin';
        $statement = $this->database->prepare('SELECT id, username, password_hash, session_version' . $admin . ' FROM users WHERE username = ?');
        $statement->execute([$username]);
        return $statement->fetch() ?: null;
    }

    public function findSessionUser(int $id): ?array
    {
        $admin = $this->columnExists('users', 'is_admin') ? ', is_admin' : ', 0 AS is_admin';
        $statement = $this->database->prepare('SELECT id, username, session_version' . $admin . ' FROM users WHERE id = ?');
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public function isRateLimited(string $ip): bool
    {
        $cutoff = time() - 900;
        $cleanup = $this->database->prepare('DELETE FROM auth_attempts WHERE attempted_at < ?');
        $cleanup->execute([$cutoff]);
        $statement = $this->database->prepare('SELECT COUNT(*) FROM auth_attempts WHERE ip_address = ? AND attempted_at >= ?');
        $statement->execute([$ip, $cutoff]);
        return (int)$statement->fetchColumn() >= 8;
    }

    public function recordFailedLogin(string $ip): void
    {
        $statement = $this->database->prepare('INSERT INTO auth_attempts (ip_address, attempted_at) VALUES (?, ?)');
        $statement->execute([$ip, time()]);
    }

    public function clearFailedLogins(string $ip): void
    {
        $statement = $this->database->prepare('DELETE FROM auth_attempts WHERE ip_address = ?');
        $statement->execute([$ip]);
    }

    public function rehashPassword(int $userId, string $password): void
    {
        $statement = $this->database->prepare('UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $statement->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    }

    private function columnExists(string $table, string $column): bool
    {
        $columns = $this->database->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        return in_array($column, array_column($columns, 'name'), true);
    }

    private function initializeProfile(int $userId, string $username): void
    {
        if (!$this->columnExists('users', 'profile_slug')) return;
        $base = mb_strtolower(trim($username));
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
        $base = trim((string)preg_replace('/[^a-z0-9]+/', '-', $base), '-');
        if ($base === '') $base = 'user';
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE users
            SET display_name = CASE WHEN display_name = '' THEN ? ELSE display_name END,
                profile_slug = CASE WHEN profile_slug IS NULL OR profile_slug = '' THEN ? ELSE profile_slug END
            WHERE id = ?
        SQL);
        $statement->execute([$username, $base . '-' . $userId, $userId]);
    }
}

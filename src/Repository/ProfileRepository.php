<?php
declare(strict_types=1);

namespace N3\Repository;

use PDO;

final class ProfileRepository
{
    public function __construct(private readonly PDO $database) {}

    public function findBySlug(string $slug): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT id, username, display_name, biography, profile_slug, profile_visibility, avatar_reference
            FROM users
            WHERE profile_slug = ? COLLATE NOCASE
        SQL);
        $statement->execute([$slug]);
        return $statement->fetch() ?: null;
    }

    public function findByUserId(int $userId): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT id, username, display_name, profile_slug, profile_visibility, avatar_reference
            FROM users
            WHERE id = ?
        SQL);
        $statement->execute([$userId]);
        return $statement->fetch() ?: null;
    }

    public function avatarForSlug(string $slug): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT id, profile_visibility, avatar_reference
            FROM users
            WHERE profile_slug = ? COLLATE NOCASE
        SQL);
        $statement->execute([$slug]);
        return $statement->fetch() ?: null;
    }

    public function avatarReferenceForUser(int $userId): ?string
    {
        $statement = $this->database->prepare('SELECT avatar_reference FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $reference = $statement->fetchColumn();
        if ($reference === false || $reference === null || $reference === '') return null;
        return (string)$reference;
    }

    public function settingsForUser(int $userId): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT id, username, display_name, biography, profile_slug, profile_visibility,
                   avatar_reference, session_version
            FROM users
            WHERE id = ?
        SQL);
        $statement->execute([$userId]);
        return $statement->fetch() ?: null;
    }

    public function updateSettings(
        int $userId,
        int $expectedSessionVersion,
        string $username,
        string $displayName,
        string $biography,
        string $visibility,
        int $sessionVersion,
    ): bool {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE users
            SET username = ?, display_name = ?, biography = ?, profile_visibility = ?,
                session_version = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND session_version = ?
        SQL);
        $statement->execute([
            $username,
            $displayName,
            $biography,
            $visibility,
            $sessionVersion,
            $userId,
            $expectedSessionVersion,
        ]);
        return $statement->rowCount() === 1;
    }

    public function replaceAvatarReference(int $userId, ?string $expected, ?string $replacement): bool
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE users
            SET avatar_reference = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND avatar_reference IS ?
        SQL);
        $statement->execute([$replacement, $userId, $expected]);
        return $statement->rowCount() === 1;
    }

    public function ownedPages(int $userId): array
    {
        $statement = $this->database->prepare($this->pageSelect() . <<<'SQL'
            JOIN spaces ON spaces.id = pages.space_id
            WHERE spaces.owner_id = ? AND pages.kind = 'page' AND pages.is_deleted = 0
            ORDER BY pages.updated_at DESC, pages.id DESC
        SQL);
        $statement->execute([$userId]);
        return $statement->fetchAll();
    }

    public function sharedPages(int $userId, array $accessiblePageIds): array
    {
        if ($accessiblePageIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($accessiblePageIds), '?'));
        $statement = $this->database->prepare($this->pageSelect() . <<<SQL
            JOIN spaces ON spaces.id = pages.space_id
            WHERE (spaces.owner_id IS NULL OR spaces.owner_id != ?)
              AND pages.id IN ($placeholders)
              AND pages.kind = 'page'
              AND pages.is_deleted = 0
            ORDER BY pages.updated_at DESC, pages.id DESC
        SQL);
        $statement->execute([$userId, ...array_map('intval', $accessiblePageIds)]);
        return $statement->fetchAll();
    }

    public function publishedPages(int $authorId): array
    {
        $statement = $this->database->prepare($this->pageSelect() . <<<'SQL'
            WHERE pages.author_id = ? AND pages.kind = 'page' AND pages.is_deleted = 0 AND pages.is_public = 1
            ORDER BY pages.updated_at DESC, pages.id DESC
        SQL);
        $statement->execute([$authorId]);
        return $statement->fetchAll();
    }

    public function authoredPages(int $authorId, array $visiblePageIds): array
    {
        if ($visiblePageIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($visiblePageIds), '?'));
        $statement = $this->database->prepare($this->pageSelect() . <<<SQL
            WHERE pages.author_id = ?
              AND pages.id IN ($placeholders)
              AND pages.kind = 'page'
              AND pages.is_deleted = 0
            ORDER BY pages.updated_at DESC, pages.id DESC
        SQL);
        $statement->execute([$authorId, ...array_map('intval', $visiblePageIds)]);
        return $statement->fetchAll();
    }

    private function pageSelect(): string
    {
        return <<<'SQL'
            SELECT pages.id, pages.slug, pages.title, pages.is_public, pages.created_at,
                   pages.updated_at, pages.first_published_at
            FROM pages

        SQL;
    }
}

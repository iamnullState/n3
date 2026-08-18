<?php
declare(strict_types=1);

namespace N3\Service;

use PDO;

final class AccessService
{
    public function __construct(private readonly PDO $database, private readonly int $userId) {}

    public function accessibleSpaceIds(): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT DISTINCT spaces.id
            FROM spaces
            LEFT JOIN resource_shares space_share
              ON space_share.resource_type = 'space' AND space_share.resource_id = spaces.id AND space_share.user_id = :user_id
            WHERE spaces.owner_id = :user_id
               OR space_share.id IS NOT NULL
               OR EXISTS (
                    SELECT 1 FROM resource_shares page_share
                    JOIN pages ON pages.id = page_share.resource_id
                    WHERE page_share.resource_type = 'page' AND page_share.user_id = :user_id AND pages.space_id = spaces.id
               )
            ORDER BY spaces.id
        SQL);
        $statement->execute(['user_id' => $this->userId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function accessiblePageIds(bool $includeDeleted = false): array
    {
        $sql = <<<'SQL'
            WITH RECURSIVE shared_pages(id) AS (
                SELECT resource_id FROM resource_shares WHERE resource_type = 'page' AND user_id = :user_id
                UNION
                SELECT pages.id FROM pages JOIN shared_pages ON pages.parent_id = shared_pages.id
            )
            SELECT DISTINCT pages.id
            FROM pages
            JOIN spaces ON spaces.id = pages.space_id
            LEFT JOIN resource_shares space_share
              ON space_share.resource_type = 'space' AND space_share.resource_id = spaces.id AND space_share.user_id = :user_id
            LEFT JOIN shared_pages ON shared_pages.id = pages.id
            WHERE (spaces.owner_id = :user_id OR space_share.id IS NOT NULL OR shared_pages.id IS NOT NULL)
SQL;
        if (!$includeDeleted) $sql .= ' AND pages.is_deleted = 0';
        $sql .= <<<'SQL'
            ORDER BY pages.id
        SQL;
        $statement = $this->database->prepare($sql);
        $statement->execute(['user_id' => $this->userId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function canViewSpace(int $spaceId): bool
    {
        return in_array($spaceId, $this->accessibleSpaceIds(), true);
    }

    public function canEditSpace(int $spaceId): bool
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT 1 FROM spaces
            WHERE id = :space_id AND (
                owner_id = :user_id OR EXISTS (
                    SELECT 1 FROM resource_shares
                    WHERE resource_type = 'space' AND resource_id = :space_id AND user_id = :user_id AND role = 'editor'
                )
            )
        SQL);
        $statement->execute(['space_id' => $spaceId, 'user_id' => $this->userId]);
        return (bool)$statement->fetchColumn();
    }

    public function canManageSpace(int $spaceId): bool
    {
        $statement = $this->database->prepare('SELECT 1 FROM spaces WHERE id = ? AND owner_id = ?');
        $statement->execute([$spaceId, $this->userId]);
        return (bool)$statement->fetchColumn();
    }

    public function canViewPage(int $pageId): bool
    {
        return in_array($pageId, $this->accessiblePageIds(true), true);
    }

    public function profileVisiblePageIds(int $profileUserId): array
    {
        $accessible = $this->accessiblePageIds(false);
        $params = ['author_id' => $profileUserId];
        $privateClause = '';
        if ($accessible !== []) {
            $placeholders = [];
            foreach ($accessible as $index => $pageId) {
                $key = 'page_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $pageId;
            }
            $privateClause = ' OR id IN (' . implode(',', $placeholders) . ')';
        }
        $statement = $this->database->prepare(<<<SQL
            SELECT id FROM pages
            WHERE author_id = :author_id
              AND kind = 'page'
              AND is_deleted = 0
              AND (is_public = 1$privateClause)
            ORDER BY id
        SQL);
        $statement->execute($params);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function canEditPage(int $pageId): bool
    {
        $statement = $this->database->prepare(<<<'SQL'
            WITH RECURSIVE ancestors(id, parent_id) AS (
                SELECT id, parent_id FROM pages WHERE id = :page_id
                UNION ALL
                SELECT pages.id, pages.parent_id FROM pages JOIN ancestors ON ancestors.parent_id = pages.id
            )
            SELECT 1
            FROM pages target
            JOIN spaces ON spaces.id = target.space_id
            WHERE target.id = :page_id AND (
                spaces.owner_id = :user_id
                OR EXISTS (SELECT 1 FROM resource_shares WHERE resource_type = 'space' AND resource_id = spaces.id AND user_id = :user_id AND role = 'editor')
                OR EXISTS (SELECT 1 FROM resource_shares JOIN ancestors ON ancestors.id = resource_shares.resource_id WHERE resource_type = 'page' AND user_id = :user_id AND role = 'editor')
            )
        SQL);
        $statement->execute(['page_id' => $pageId, 'user_id' => $this->userId]);
        return (bool)$statement->fetchColumn();
    }

    public function canManagePage(int $pageId): bool
    {
        $statement = $this->database->prepare('SELECT 1 FROM pages JOIN spaces ON spaces.id = pages.space_id WHERE pages.id = ? AND spaces.owner_id = ?');
        $statement->execute([$pageId, $this->userId]);
        return (bool)$statement->fetchColumn();
    }

    public function users(): array
    {
        $statement = $this->database->prepare('SELECT id, username FROM users WHERE id != ? ORDER BY username COLLATE NOCASE');
        $statement->execute([$this->userId]);
        return $statement->fetchAll();
    }

    public function shares(string $resourceType, int $resourceId): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT resource_shares.id, resource_shares.resource_type, resource_shares.resource_id, resource_shares.user_id,
                   resource_shares.role, users.username
            FROM resource_shares JOIN users ON users.id = resource_shares.user_id
            WHERE resource_shares.resource_type = ? AND resource_shares.resource_id = ?
            ORDER BY users.username COLLATE NOCASE
        SQL);
        $statement->execute([$resourceType, $resourceId]);
        return $statement->fetchAll();
    }

    public function grant(string $resourceType, int $resourceId, int $targetUserId, string $role): int
    {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO resource_shares (resource_type, resource_id, user_id, role, granted_by)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(resource_type, resource_id, user_id)
            DO UPDATE SET role = excluded.role, granted_by = excluded.granted_by, updated_at = CURRENT_TIMESTAMP
        SQL);
        $statement->execute([$resourceType, $resourceId, $targetUserId, $role, $this->userId]);
        $id = $this->database->prepare('SELECT id FROM resource_shares WHERE resource_type = ? AND resource_id = ? AND user_id = ?');
        $id->execute([$resourceType, $resourceId, $targetUserId]);
        return (int)$id->fetchColumn();
    }

    public function revoke(int $shareId): bool
    {
        $statement = $this->database->prepare(<<<'SQL'
            DELETE FROM resource_shares
            WHERE id = :id AND (
                (resource_type = 'space' AND EXISTS (SELECT 1 FROM spaces WHERE spaces.id = resource_shares.resource_id AND spaces.owner_id = :user_id))
                OR
                (resource_type = 'page' AND EXISTS (SELECT 1 FROM pages JOIN spaces ON spaces.id = pages.space_id WHERE pages.id = resource_shares.resource_id AND spaces.owner_id = :user_id))
            )
        SQL);
        $statement->execute(['id' => $shareId, 'user_id' => $this->userId]);
        return $statement->rowCount() === 1;
    }

    public function sharedWithMe(): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT resource_shares.resource_type, resource_shares.resource_id, resource_shares.role,
                   CASE WHEN resource_shares.resource_type = 'space' THEN spaces.name ELSE pages.title END AS title,
                   owners.username AS owner_name
            FROM resource_shares
            LEFT JOIN spaces ON resource_shares.resource_type = 'space' AND spaces.id = resource_shares.resource_id
            LEFT JOIN pages ON resource_shares.resource_type = 'page' AND pages.id = resource_shares.resource_id
            LEFT JOIN spaces page_spaces ON page_spaces.id = pages.space_id
            LEFT JOIN users owners ON owners.id = COALESCE(spaces.owner_id, page_spaces.owner_id)
            WHERE resource_shares.user_id = ?
            ORDER BY resource_shares.updated_at DESC
        SQL);
        $statement->execute([$this->userId]);
        return $statement->fetchAll();
    }
}

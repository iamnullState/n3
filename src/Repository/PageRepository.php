<?php
declare(strict_types=1);

namespace N3\Repository;

use N3\Service\FeatureImageService;
use PDO;

final class PageRepository
{
    public function __construct(private readonly PDO $database) {}

    public function find(int $id, bool $includeDeleted = false): ?array
    {
        $sql = 'SELECT * FROM pages WHERE id = ?' . ($includeDeleted ? '' : ' AND is_deleted = 0');
        $statement = $this->database->prepare($sql);
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $statement = $this->database->prepare("SELECT * FROM pages WHERE slug = ? AND kind = 'page' AND is_deleted = 0");
        $statement->execute([$slug]);
        return $statement->fetch() ?: null;
    }

    public function active(?array $ids = null): array
    {
        $sql = "SELECT id, space_id, parent_id, title, slug, kind, position, is_favorite, is_public, content_revision, created_at, updated_at FROM pages WHERE is_deleted = 0";
        $params = [];
        if ($ids !== null) {
            if ($ids === []) return [];
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_map('intval', $ids);
        }
        $statement = $this->database->prepare($sql . ' ORDER BY position, title');
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function search(string $query, ?array $ids = null): array
    {
        if ($ids === []) return [];
        $sql = "SELECT id, space_id, title, substr(trim(replace(replace(content, '<', ' <'), '>', '> ')), 1, 240) AS excerpt, updated_at FROM pages WHERE kind = 'page' AND is_deleted = 0 AND (title LIKE :q OR content LIKE :q)";
        $params = ['q' => "%$query%", 'start' => "$query%"];
        if ($ids !== null) {
            $placeholders = [];
            foreach (array_values($ids) as $index => $id) {
                $key = 'id_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = (int)$id;
            }
            $sql .= ' AND id IN (' . implode(',', $placeholders) . ')';
        }
        $statement = $this->database->prepare($sql . ' ORDER BY CASE WHEN title LIKE :start THEN 0 ELSE 1 END, updated_at DESC LIMIT 30');
        $statement->execute($params);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) $row['excerpt'] = trim(strip_tags($row['excerpt']));
        return $rows;
    }

    public function parentSpace(int $id): ?int
    {
        $statement = $this->database->prepare('SELECT space_id FROM pages WHERE id = ? AND is_deleted = 0');
        $statement->execute([$id]);
        $spaceId = $statement->fetchColumn();
        return $spaceId === false ? null : (int)$spaceId;
    }

    public function isDescendant(int $pageId, int $possibleDescendantId): bool
    {
        $statement = $this->database->prepare('WITH RECURSIVE descendants(id) AS (SELECT id FROM pages WHERE parent_id = ? UNION ALL SELECT pages.id FROM pages JOIN descendants ON pages.parent_id = descendants.id) SELECT 1 FROM descendants WHERE id = ?');
        $statement->execute([$pageId, $possibleDescendantId]);
        return (bool)$statement->fetchColumn();
    }

    public function create(int $spaceId, ?int $parentId, string $title, string $kind, string $slugBase, ?int $actorId = null): int
    {
        $position = (int)$this->database->query('SELECT COALESCE(MAX(position), -1) + 1 FROM pages')->fetchColumn();
        $authorshipColumns = $actorId === null ? '' : ', author_id, last_editor_id';
        $authorshipValues = $actorId === null ? '' : ', ?, ?';
        $statement = $this->database->prepare("INSERT INTO pages (space_id, parent_id, title, kind, content, position$authorshipColumns) VALUES (?, ?, ?, ?, ?, ?$authorshipValues)");
        $values = [$spaceId, $parentId, $title, $kind, '<p></p>', $position];
        if ($actorId !== null) array_push($values, $actorId, $actorId);
        $statement->execute($values);
        $id = (int)$this->database->lastInsertId();
        if ($kind === 'page') {
            $this->database->prepare('UPDATE pages SET slug = ? WHERE id = ?')->execute([$slugBase . '-' . $id, $id]);
            $this->database->prepare("INSERT INTO page_revisions (page_id, revision, title, content, source) SELECT id, content_revision, title, content, 'initial' FROM pages WHERE id = ?")->execute([$id]);
        }
        return $id;
    }

    public function reorder(int $sourceId, int $oldSpaceId, int $spaceId, ?int $parentId, array $orderedIds, ?int $actorId = null): bool
    {
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            $siblings = $this->database->prepare('SELECT id FROM pages WHERE space_id = ? AND parent_id IS ? AND is_deleted = 0 AND id != ? ORDER BY position, title');
            $siblings->execute([$spaceId, $parentId, $sourceId]);
            $expected = array_map('intval', array_column($siblings->fetchAll(), 'id'));
            $expected[] = $sourceId;
            $actual = $orderedIds;
            sort($expected);
            sort($actual);
            if ($expected !== $actual) {
                $this->database->exec('ROLLBACK');
                return false;
            }
            $editorSet = $actorId === null ? '' : ', last_editor_id = ?';
            $move = $this->database->prepare("UPDATE pages SET parent_id = ?, space_id = ?, updated_at = CURRENT_TIMESTAMP$editorSet WHERE id = ?");
            $moveValues = [$parentId, $spaceId];
            if ($actorId !== null) $moveValues[] = $actorId;
            $moveValues[] = $sourceId;
            $move->execute($moveValues);
            if ($spaceId !== $oldSpaceId) $this->moveDescendantsToSpace($sourceId, $spaceId, $actorId);
            $position = $this->database->prepare('UPDATE pages SET position = ? WHERE id = ?');
            foreach ($orderedIds as $index => $id) $position->execute([$index, $id]);
            $this->database->exec('COMMIT');
            return true;
        } catch (\Throwable $error) {
            try { $this->database->exec('ROLLBACK'); } catch (\Throwable) {}
            throw $error;
        }
    }

    public function update(
        int $id,
        array $changes,
        bool $contentWrite,
        int $baseRevision,
        int $oldSpaceId,
        int $newSpaceId,
        ?int $actorId = null,
        bool $firstPublication = false,
    ): ?array
    {
        if ($contentWrite) $this->database->exec('BEGIN IMMEDIATE');
        try {
            $sets = [];
            $values = [];
            $allowed = ['title', 'content', 'is_favorite', 'is_public', 'parent_id', 'space_id', 'position', 'feature_image', 'feature_image_opacity'];
            foreach ($changes as $field => $value) {
                if (!in_array($field, $allowed, true)) throw new \InvalidArgumentException("Unsupported page field: $field");
                $sets[] = $field . ' = ?';
                $values[] = $value;
            }
            if ($contentWrite) $sets[] = 'content_revision = content_revision + 1';
            $meaningfulWrite = array_diff(array_keys($changes), ['is_favorite']) !== [];
            if ($actorId !== null && $meaningfulWrite) {
                $sets[] = 'last_editor_id = ?';
                $values[] = $actorId;
            }
            if ($firstPublication) $sets[] = 'first_published_at = COALESCE(first_published_at, CURRENT_TIMESTAMP)';
            $values[] = $id;
            if ($contentWrite) $values[] = $baseRevision;
            $statement = $this->database->prepare('UPDATE pages SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?' . ($contentWrite ? ' AND content_revision = ?' : ''));
            $statement->execute($values);
            if ($contentWrite && $statement->rowCount() !== 1) {
                $this->database->exec('ROLLBACK');
                return null;
            }
            if ($newSpaceId !== $oldSpaceId) $this->moveDescendantsToSpace($id, $newSpaceId, $actorId);
            $saved = $this->database->prepare('SELECT title, content, updated_at, content_revision FROM pages WHERE id = ?');
            $saved->execute([$id]);
            $page = $saved->fetch();
            if ($contentWrite) {
                $revision = $this->database->prepare("INSERT INTO page_revisions (page_id, revision, title, content, source) VALUES (?, ?, ?, ?, 'edit')");
                $revision->execute([$id, $page['content_revision'], $page['title'], $page['content']]);
                $this->database->exec('COMMIT');
            }
            return $page;
        } catch (\Throwable $error) {
            if ($contentWrite) try { $this->database->exec('ROLLBACK'); } catch (\Throwable) {}
            throw $error;
        }
    }

    public function softDeleteTree(int $id, ?int $actorId = null): void
    {
        $editorSet = $actorId === null ? '' : ', last_editor_id = ?';
        $statement = $this->database->prepare("WITH RECURSIVE descendants(id) AS (SELECT id FROM pages WHERE id = ? UNION ALL SELECT pages.id FROM pages JOIN descendants ON pages.parent_id = descendants.id) UPDATE pages SET is_deleted = 1, updated_at = CURRENT_TIMESTAMP$editorSet WHERE id IN (SELECT id FROM descendants)");
        $values = [$id];
        if ($actorId !== null) $values[] = $actorId;
        $statement->execute($values);
    }

    public function duplicate(array $page, string $slugBase, ?int $actorId = null): int
    {
        $authorshipColumns = $actorId === null ? '' : ', author_id, last_editor_id';
        $authorshipValues = $actorId === null ? '' : ', ?, ?';
        $statement = $this->database->prepare("INSERT INTO pages (space_id, parent_id, title, content, position, feature_image, feature_image_opacity$authorshipColumns) VALUES (?, ?, ?, ?, ?, ?, ?$authorshipValues)");
        $values = [
            $page['space_id'],
            $page['parent_id'],
            $page['title'] . ' copy',
            $page['content'],
            (int)$page['position'] + 1,
            $page['feature_image'] ?? null,
            (int)($page['feature_image_opacity'] ?? FeatureImageService::DEFAULT_OPACITY),
        ];
        if ($actorId !== null) array_push($values, $actorId, $actorId);
        $statement->execute($values);
        $id = (int)$this->database->lastInsertId();
        $this->database->prepare('UPDATE pages SET slug = ? WHERE id = ?')->execute([$slugBase . '-' . $id, $id]);
        $this->database->prepare("INSERT INTO page_revisions (page_id, revision, title, content, source) SELECT id, content_revision, title, content, 'duplicate' FROM pages WHERE id = ?")->execute([$id]);
        return $id;
    }

    private function moveDescendantsToSpace(int $id, int $spaceId, ?int $actorId = null): void
    {
        $editorSet = $actorId === null ? '' : ', last_editor_id = ?';
        $statement = $this->database->prepare("WITH RECURSIVE descendants(id) AS (SELECT id FROM pages WHERE parent_id = ? UNION ALL SELECT pages.id FROM pages JOIN descendants ON pages.parent_id = descendants.id) UPDATE pages SET space_id = ?, updated_at = CURRENT_TIMESTAMP$editorSet WHERE id IN (SELECT id FROM descendants)");
        $values = [$id, $spaceId];
        if ($actorId !== null) $values[] = $actorId;
        $statement->execute($values);
    }
}

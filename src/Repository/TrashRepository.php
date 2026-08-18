<?php
declare(strict_types=1);

namespace N3\Repository;

use PDO;

final class TrashRepository
{
    public function __construct(private readonly PDO $database) {}

    public function roots(?array $ids = null): array
    {
        if ($ids === []) return [];
        $sql = 'SELECT id, title, updated_at FROM pages WHERE is_deleted = 1 AND (parent_id IS NULL OR parent_id NOT IN (SELECT id FROM pages WHERE is_deleted = 1))';
        $params = [];
        if ($ids !== null) {
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_map('intval', $ids);
        }
        $statement = $this->database->prepare($sql . ' ORDER BY updated_at DESC');
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function restoreTree(int $id, ?int $actorId = null): void
    {
        $editorSet = $actorId === null ? '' : ', last_editor_id = ?';
        $statement = $this->database->prepare("WITH RECURSIVE descendants(id) AS (SELECT id FROM pages WHERE id = ? UNION ALL SELECT pages.id FROM pages JOIN descendants ON pages.parent_id = descendants.id) UPDATE pages SET is_deleted = 0, updated_at = CURRENT_TIMESTAMP$editorSet WHERE id IN (SELECT id FROM descendants)");
        $values = [$id];
        if ($actorId !== null) $values[] = $actorId;
        $statement->execute($values);
    }

    public function deleteTree(int $id): void
    {
        $statement = $this->database->prepare('WITH RECURSIVE descendants(id) AS (SELECT id FROM pages WHERE id = ? UNION ALL SELECT pages.id FROM pages JOIN descendants ON pages.parent_id = descendants.id) DELETE FROM pages WHERE id IN (SELECT id FROM descendants)');
        $statement->execute([$id]);
    }
}

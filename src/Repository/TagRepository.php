<?php
declare(strict_types=1);

namespace N3\Repository;

use PDO;

final class TagRepository
{
    public function __construct(private readonly PDO $database) {}

    public function forPage(int $pageId): array
    {
        $statement = $this->database->prepare(
            'SELECT tags.name FROM tags JOIN page_tags ON page_tags.tag_id = tags.id WHERE page_tags.page_id = ? ORDER BY tags.name COLLATE NOCASE'
        );
        $statement->execute([$pageId]);
        return array_column($statement->fetchAll(), 'name');
    }

    public function replaceForPage(int $pageId, array $names, ?int $actorId = null): void
    {
        $this->database->beginTransaction();
        try {
            $this->database->prepare('DELETE FROM page_tags WHERE page_id = ?')->execute([$pageId]);
            $insert = $this->database->prepare('INSERT INTO tags (name) VALUES (?) ON CONFLICT(name) DO NOTHING');
            $tagId = $this->database->prepare('SELECT id FROM tags WHERE name = ? COLLATE NOCASE');
            $link = $this->database->prepare('INSERT INTO page_tags (page_id, tag_id) VALUES (?, ?)');
            foreach ($names as $name) {
                $insert->execute([$name]);
                $tagId->execute([$name]);
                $link->execute([$pageId, (int)$tagId->fetchColumn()]);
            }
            $this->database->exec('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM page_tags)');
            if ($actorId !== null) {
                $this->database->prepare('UPDATE pages SET last_editor_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$actorId, $pageId]);
            }
            $this->database->commit();
        } catch (\Throwable $error) {
            $this->database->rollBack();
            throw $error;
        }
    }

    public function relatedForPage(int $pageId, array $visiblePageIds, int $limit = 6, bool $publicOnly = false): array
    {
        if ($visiblePageIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($visiblePageIds), '?'));
        $sql = <<<SQL
            SELECT pages.id, pages.title, pages.slug, COUNT(*) AS shared_tags
            FROM page_tags source_tags
            JOIN page_tags related_tags ON related_tags.tag_id = source_tags.tag_id AND related_tags.page_id != source_tags.page_id
            JOIN pages ON pages.id = related_tags.page_id
            WHERE source_tags.page_id = ?
              AND pages.id IN ($placeholders)
              AND pages.kind = 'page'
              AND pages.is_deleted = 0
        SQL;
        if ($publicOnly) $sql .= ' AND pages.is_public = 1';
        $sql .= ' GROUP BY pages.id ORDER BY shared_tags DESC, pages.updated_at DESC LIMIT ?';
        $statement = $this->database->prepare($sql);
        $statement->execute([$pageId, ...array_map('intval', $visiblePageIds), $limit]);
        return $statement->fetchAll();
    }
}

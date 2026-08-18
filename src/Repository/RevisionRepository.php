<?php
declare(strict_types=1);

namespace N3\Repository;

use PDO;

final class RevisionRepository
{
    public function __construct(private readonly PDO $database) {}

    public function forPage(int $pageId, int $limit = 100): array
    {
        $statement = $this->database->prepare(
            'SELECT revision, title, source, created_at, length(content) AS content_size FROM page_revisions WHERE page_id = ? ORDER BY revision DESC LIMIT ?'
        );
        $statement->bindValue(1, $pageId, PDO::PARAM_INT);
        $statement->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function find(int $pageId, int $revision): ?array
    {
        $statement = $this->database->prepare(
            'SELECT revision, title, content, source, created_at FROM page_revisions WHERE page_id = ? AND revision = ?'
        );
        $statement->execute([$pageId, $revision]);
        return $statement->fetch() ?: null;
    }

    public function snapshot(int $pageId, int $revision): ?array
    {
        $statement = $this->database->prepare('SELECT title, content FROM page_revisions WHERE page_id = ? AND revision = ?');
        $statement->execute([$pageId, $revision]);
        return $statement->fetch() ?: null;
    }

    public function restore(int $pageId, array $snapshot, int $baseRevision, ?int $actorId = null): ?array
    {
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            $editorSet = $actorId === null ? '' : ', last_editor_id = ?';
            $restore = $this->database->prepare("UPDATE pages SET title = ?, content = ?, content_revision = content_revision + 1, updated_at = CURRENT_TIMESTAMP$editorSet WHERE id = ? AND content_revision = ?");
            $values = [$snapshot['title'], $snapshot['content']];
            if ($actorId !== null) $values[] = $actorId;
            array_push($values, $pageId, $baseRevision);
            $restore->execute($values);
            if ($restore->rowCount() !== 1) {
                $this->database->exec('ROLLBACK');
                return null;
            }
            $current = $this->database->prepare('SELECT title, content, updated_at, content_revision FROM pages WHERE id = ?');
            $current->execute([$pageId]);
            $page = $current->fetch();
            $insert = $this->database->prepare("INSERT INTO page_revisions (page_id, revision, title, content, source) VALUES (?, ?, ?, ?, 'restore')");
            $insert->execute([$pageId, $page['content_revision'], $page['title'], $page['content']]);
            $this->database->exec('COMMIT');
            return $page;
        } catch (\Throwable $error) {
            try { $this->database->exec('ROLLBACK'); } catch (\Throwable) {}
            throw $error;
        }
    }
}

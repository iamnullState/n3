<?php
declare(strict_types=1);

namespace N3\Repository;

use PDO;

final class PageReferenceRepository
{
    public function __construct(private readonly PDO $database) {}

    public function forPage(int $pageId): array
    {
        $statement = $this->database->prepare('SELECT id, label, url, position FROM page_references WHERE page_id = ? ORDER BY position, id');
        $statement->execute([$pageId]);
        return $statement->fetchAll();
    }

    public function replaceForPage(int $pageId, array $references, ?int $actorId = null): void
    {
        $this->database->beginTransaction();
        try {
            $this->database->prepare('DELETE FROM page_references WHERE page_id = ?')->execute([$pageId]);
            $insert = $this->database->prepare('INSERT INTO page_references (page_id, label, url, position) VALUES (?, ?, ?, ?)');
            foreach ($references as $position => $reference) {
                $insert->execute([$pageId, $reference['label'], $reference['url'], $position]);
            }
            if ($actorId !== null) {
                $this->database->prepare('UPDATE pages SET last_editor_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$actorId, $pageId]);
            }
            $this->database->commit();
        } catch (\Throwable $error) {
            $this->database->rollBack();
            throw $error;
        }
    }
}

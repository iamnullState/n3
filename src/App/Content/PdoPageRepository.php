<?php

declare(strict_types=1);

namespace N3\App\Content;

use PDO;
use RuntimeException;

final readonly class PdoPageRepository implements PageRepository
{
    public function __construct(private PDO $connection)
    {
    }

    public function listForAdministration(): array
    {
        $rows = $this->connection->query(
            'SELECT id, title, slug, excerpt, body, status, author_id, updated_by, lock_version, '
            . 'published_at, created_at, updated_at FROM pages ORDER BY updated_at DESC, id DESC LIMIT 200',
        )->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(int $id): ?Page
    {
        $statement = $this->connection->prepare(
            'SELECT id, title, slug, excerpt, body, status, author_id, updated_by, lock_version, '
            . 'published_at, created_at, updated_at FROM pages WHERE id = :id LIMIT 1',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findPublishedBySlug(string $slug): ?Page
    {
        $statement = $this->connection->prepare(
            'SELECT id, title, slug, excerpt, body, status, author_id, updated_by, lock_version, '
            . "published_at, created_at, updated_at FROM pages WHERE slug = :slug AND status = 'published' LIMIT 1",
        );
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function createDraft(string $title, string $slug, string $excerpt, string $body, int $actorId): int
    {
        $statement = $this->connection->prepare(
            "INSERT INTO pages (title, slug, excerpt, body, status, author_id, updated_by) "
            . "VALUES (:title, :slug, :excerpt, :body, 'draft', :author_id, :updated_by)",
        );
        $statement->execute([
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $excerpt === '' ? null : $excerpt,
            'body' => $body,
            'author_id' => $actorId,
            'updated_by' => $actorId,
        ]);
        $id = $this->connection->lastInsertId();
        if (!ctype_digit($id)) {
            throw new RuntimeException('MariaDB did not return a valid page identifier.');
        }

        return (int) $id;
    }

    public function updateDraft(
        int $id,
        string $title,
        string $slug,
        string $excerpt,
        string $body,
        int $actorId,
        int $expectedVersion,
    ): bool {
        $statement = $this->connection->prepare(
            "UPDATE pages SET title = :title, slug = :slug, excerpt = :excerpt, body = :body, "
            . 'updated_by = :updated_by, lock_version = lock_version + 1 '
            . "WHERE id = :id AND status = 'draft' AND lock_version = :expected_version",
        );
        $statement->execute([
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $excerpt === '' ? null : $excerpt,
            'body' => $body,
            'updated_by' => $actorId,
            'id' => $id,
            'expected_version' => $expectedVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function transition(int $id, string $from, string $to, int $actorId, int $expectedVersion): bool
    {
        $publishedAt = $to === 'published' ? 'CURRENT_TIMESTAMP(6)' : 'NULL';
        $statement = $this->connection->prepare(
            "UPDATE pages SET status = :to_status, published_at = {$publishedAt}, updated_by = :updated_by, "
            . 'lock_version = lock_version + 1 WHERE id = :id AND status = :from_status '
            . 'AND lock_version = :expected_version',
        );
        $statement->execute([
            'to_status' => $to,
            'updated_by' => $actorId,
            'id' => $id,
            'from_status' => $from,
            'expected_version' => $expectedVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Page
    {
        return new Page(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['slug'],
            (string) ($row['excerpt'] ?? ''),
            (string) $row['body'],
            (string) $row['status'],
            (int) $row['author_id'],
            (int) $row['updated_by'],
            (int) $row['lock_version'],
            $row['published_at'] === null ? null : (string) $row['published_at'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}

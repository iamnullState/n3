<?php

declare(strict_types=1);

namespace N3\Module\Blog;

use N3\Core\Database\TransactionManager;
use PDO;
use RuntimeException;

final readonly class PdoBlogRepository implements BlogRepository
{
    public function __construct(private PDO $connection, private TransactionManager $transactions)
    {
    }

    public function listForAdministration(): array
    {
        $rows = $this->connection->query(sprintf(
            "SELECT id, title, slug, excerpt, '' AS body, status, author_id, updated_by, lock_version, "
            . 'published_at, created_at, updated_at FROM `%s` ORDER BY updated_at DESC, id DESC LIMIT 200',
            BlogSchema::postsTable(),
        ))->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(int $id): ?BlogPost
    {
        $statement = $this->connection->prepare(sprintf(
            'SELECT id, title, slug, excerpt, body, status, author_id, updated_by, lock_version, '
            . 'published_at, created_at, updated_at FROM `%s` WHERE id = :id LIMIT 1',
            BlogSchema::postsTable(),
        ));
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findPublishedBySlug(string $slug): ?BlogPost
    {
        $statement = $this->connection->prepare(sprintf(
            'SELECT id, title, slug, excerpt, body, status, author_id, updated_by, lock_version, '
            . "published_at, created_at, updated_at FROM `%s` WHERE slug = :slug AND status = 'published' LIMIT 1",
            BlogSchema::postsTable(),
        ));
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function countPublished(): int
    {
        return (int) $this->connection->query(sprintf(
            "SELECT COUNT(*) FROM `%s` WHERE status = 'published'",
            BlogSchema::postsTable(),
        ))->fetchColumn();
    }

    public function listPublished(int $limit, int $offset): array
    {
        if ($limit < 1 || $limit > BlogService::PAGE_SIZE || $offset < 0
            || $offset > (BlogService::MAXIMUM_PAGE - 1) * BlogService::PAGE_SIZE) {
            throw new \InvalidArgumentException('Blog pagination is outside its bounded range.');
        }
        $statement = $this->connection->prepare(sprintf(
            "SELECT id, title, slug, excerpt, '' AS body, status, author_id, updated_by, lock_version, "
            . "published_at, created_at, updated_at FROM `%s` WHERE status = 'published' "
            . 'ORDER BY published_at DESC, id DESC LIMIT :limit OFFSET :offset',
            BlogSchema::postsTable(),
        ));
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    public function createDraft(string $title, string $slug, string $excerpt, string $body, int $actorId, string $requestId): int
    {
        return $this->transactions->run(function () use ($title, $slug, $excerpt, $body, $actorId, $requestId): int {
            $statement = $this->connection->prepare(sprintf(
                "INSERT INTO `%s` (title, slug, excerpt, body, status, author_id, updated_by) "
                . "VALUES (:title, :slug, :excerpt, :body, 'draft', :author_id, :updated_by)",
                BlogSchema::postsTable(),
            ));
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
                throw new RuntimeException('MariaDB did not return a valid Blog post identifier.');
            }
            $this->event((int) $id, $actorId, 'created', null, 'draft', $requestId);

            return (int) $id;
        });
    }

    public function updateDraft(
        int $id,
        string $title,
        string $slug,
        string $excerpt,
        string $body,
        int $actorId,
        int $expectedVersion,
        string $requestId,
    ): bool {
        return $this->transactions->run(function () use ($id, $title, $slug, $excerpt, $body, $actorId, $expectedVersion, $requestId): bool {
            $statement = $this->connection->prepare(sprintf(
                'UPDATE `%s` SET title = :title, slug = :slug, excerpt = :excerpt, body = :body, '
                . 'updated_by = :updated_by, lock_version = lock_version + 1 '
                . "WHERE id = :id AND status = 'draft' AND lock_version = :expected_version",
                BlogSchema::postsTable(),
            ));
            $statement->execute([
                'title' => $title,
                'slug' => $slug,
                'excerpt' => $excerpt === '' ? null : $excerpt,
                'body' => $body,
                'updated_by' => $actorId,
                'id' => $id,
                'expected_version' => $expectedVersion,
            ]);
            if ($statement->rowCount() !== 1) {
                return false;
            }
            $this->event($id, $actorId, 'updated', 'draft', 'draft', $requestId);

            return true;
        });
    }

    public function transition(
        int $id,
        string $from,
        string $to,
        int $actorId,
        int $expectedVersion,
        string $requestId,
    ): bool {
        if (!in_array([$from, $to], [['draft', 'published'], ['published', 'draft']], true)) {
            throw new \InvalidArgumentException('Blog lifecycle transition is invalid.');
        }

        return $this->transactions->run(function () use ($id, $from, $to, $actorId, $expectedVersion, $requestId): bool {
            $publishedAt = $to === 'published' ? 'UTC_TIMESTAMP(6)' : 'NULL';
            $statement = $this->connection->prepare(sprintf(
                'UPDATE `%s` SET status = :to_status, published_at = %s, updated_by = :updated_by, '
                . 'lock_version = lock_version + 1 WHERE id = :id AND status = :from_status '
                . 'AND lock_version = :expected_version',
                BlogSchema::postsTable(),
                $publishedAt,
            ));
            $statement->execute([
                'to_status' => $to,
                'updated_by' => $actorId,
                'id' => $id,
                'from_status' => $from,
                'expected_version' => $expectedVersion,
            ]);
            if ($statement->rowCount() !== 1) {
                return false;
            }
            $this->event($id, $actorId, $to === 'published' ? 'published' : 'unpublished', $from, $to, $requestId);

            return true;
        });
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): BlogPost
    {
        return new BlogPost(
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

    private function event(
        int $postId,
        int $actorId,
        string $event,
        ?string $from,
        string $to,
        string $requestId,
    ): void {
        if (!in_array($event, ['created', 'updated', 'published', 'unpublished'], true)) {
            throw new \InvalidArgumentException('Blog events must use the controlled vocabulary.');
        }
        $statement = $this->connection->prepare(sprintf(
            'INSERT INTO `%s` (post_id, actor_user_id, event_type, from_status, to_status, request_id) '
            . 'VALUES (:post_id, :actor_id, :event_type, :from_status, :to_status, :request_id)',
            BlogSchema::eventsTable(),
        ));
        $statement->execute([
            'post_id' => $postId,
            'actor_id' => $actorId,
            'event_type' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'request_id' => preg_match('/^[a-f0-9]{16}$/D', $requestId) === 1 ? $requestId : null,
        ]);
    }
}

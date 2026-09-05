<?php

declare(strict_types=1);

namespace N3\Module\Blog;

use Closure;
use RuntimeException;

final class LazyBlogRepository implements BlogRepository
{
    private ?BlogRepository $repository = null;

    /** @param Closure(): BlogRepository $factory */
    public function __construct(private readonly Closure $factory)
    {
    }

    public function listForAdministration(): array { return $this->repository()->listForAdministration(); }
    public function findById(int $id): ?BlogPost { return $this->repository()->findById($id); }
    public function findPublishedBySlug(string $slug): ?BlogPost { return $this->repository()->findPublishedBySlug($slug); }
    public function countPublished(): int { return $this->repository()->countPublished(); }
    public function listPublished(int $limit, int $offset): array { return $this->repository()->listPublished($limit, $offset); }
    public function createDraft(string $title, string $slug, string $excerpt, string $body, int $actorId, string $requestId): int
    { return $this->repository()->createDraft($title, $slug, $excerpt, $body, $actorId, $requestId); }
    public function updateDraft(int $id, string $title, string $slug, string $excerpt, string $body, int $actorId, int $expectedVersion, string $requestId): bool
    { return $this->repository()->updateDraft($id, $title, $slug, $excerpt, $body, $actorId, $expectedVersion, $requestId); }
    public function transition(int $id, string $from, string $to, int $actorId, int $expectedVersion, string $requestId): bool
    { return $this->repository()->transition($id, $from, $to, $actorId, $expectedVersion, $requestId); }

    private function repository(): BlogRepository
    {
        if ($this->repository === null) {
            $repository = ($this->factory)();
            if (!$repository instanceof BlogRepository) {
                throw new RuntimeException('The Blog repository factory returned an invalid repository.');
            }
            $this->repository = $repository;
        }

        return $this->repository;
    }
}

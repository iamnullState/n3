<?php

declare(strict_types=1);

namespace N3\Module\Blog;

interface BlogRepository
{
    /** @return list<BlogPost> */
    public function listForAdministration(): array;

    public function findById(int $id): ?BlogPost;

    public function findPublishedBySlug(string $slug): ?BlogPost;

    public function countPublished(): int;

    /** @return list<BlogPost> */
    public function listPublished(int $limit, int $offset): array;

    public function createDraft(string $title, string $slug, string $excerpt, string $body, int $actorId, string $requestId): int;

    public function updateDraft(
        int $id,
        string $title,
        string $slug,
        string $excerpt,
        string $body,
        int $actorId,
        int $expectedVersion,
        string $requestId,
    ): bool;

    public function transition(
        int $id,
        string $from,
        string $to,
        int $actorId,
        int $expectedVersion,
        string $requestId,
    ): bool;
}

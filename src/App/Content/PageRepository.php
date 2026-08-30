<?php

declare(strict_types=1);

namespace N3\App\Content;

interface PageRepository
{
    /** @return list<Page> */
    public function listForAdministration(): array;

    public function findById(int $id): ?Page;

    public function findPublishedBySlug(string $slug): ?Page;

    public function createDraft(string $title, string $slug, string $excerpt, string $body, int $actorId): int;

    public function updateDraft(
        int $id,
        string $title,
        string $slug,
        string $excerpt,
        string $body,
        int $actorId,
        int $expectedVersion,
    ): bool;

    public function transition(int $id, string $from, string $to, int $actorId, int $expectedVersion): bool;
}

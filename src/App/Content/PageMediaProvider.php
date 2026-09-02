<?php

declare(strict_types=1);

namespace N3\App\Content;

interface PageMediaProvider
{
    /** @return list<PageMediaOption> */
    public function options(int $pageId): array;

    public function attachment(int $pageId): ?PageMediaAttachment;

    public function updateDraft(
        int $pageId,
        ?string $publicId,
        string $altText,
        int $actorId,
        int $expectedVersion,
        string $requestId,
    ): PageMediaMutationOutcome;
}

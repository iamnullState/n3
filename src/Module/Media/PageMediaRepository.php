<?php

declare(strict_types=1);

namespace N3\Module\Media;

use N3\App\Content\PageMediaAttachment;
use N3\App\Content\PageMediaOption;

interface PageMediaRepository
{
    /** @return list<PageMediaOption> */
    public function options(int $pageId): array;

    public function attachment(int $pageId): ?PageMediaAttachment;

    /** @return 'attached'|'detached'|'unchanged'|'conflict'|'missing_asset' */
    public function updateDraft(
        int $pageId,
        ?string $publicId,
        string $altText,
        int $actorId,
        int $expectedVersion,
        string $requestId,
    ): string;

    public function isPubliclyAttached(string $publicId): bool;
}

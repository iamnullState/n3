<?php

declare(strict_types=1);

namespace N3\Module\Media;

use N3\App\Content\PageMediaAttachment;
use N3\App\Content\PageMediaMutationOutcome;
use N3\App\Content\PageMediaProvider;

final readonly class PageMediaService implements PageMediaProvider
{
    public function __construct(private PageMediaRepository $repository)
    {
    }

    public function options(int $pageId): array
    {
        return $this->repository->options($pageId);
    }

    public function attachment(int $pageId): ?PageMediaAttachment
    {
        return $this->repository->attachment($pageId);
    }

    public function updateDraft(
        int $pageId,
        ?string $publicId,
        string $altText,
        int $actorId,
        int $expectedVersion,
        string $requestId,
    ): PageMediaMutationOutcome {
        $publicId = $publicId === null || trim($publicId) === '' ? null : trim($publicId);
        $altText = trim($altText);
        if ($publicId !== null && !preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            return new PageMediaMutationOutcome(['media' => 'Choose an image from the Media library.']);
        }
        if ($publicId !== null && (!mb_check_encoding($altText, 'UTF-8') || mb_strlen($altText) < 2
            || mb_strlen($altText) > 300 || preg_match('/[\x00-\x1F\x7F]/u', $altText) === 1)) {
            return new PageMediaMutationOutcome(['alt_text' => 'Describe the image in 2 to 300 characters without control characters.']);
        }
        if ($pageId < 1 || $actorId < 1 || $expectedVersion < 1 || !preg_match('/^[a-f0-9]{16}$/D', $requestId)) {
            throw new \InvalidArgumentException('Page Media mutations require valid trusted context.');
        }

        return match ($this->repository->updateDraft(
            $pageId, $publicId, $publicId === null ? '' : $altText, $actorId, $expectedVersion, $requestId,
        )) {
            'attached', 'detached', 'unchanged' => new PageMediaMutationOutcome(),
            'missing_asset' => new PageMediaMutationOutcome(['media' => 'Choose an available image from the Media library.']),
            'conflict' => new PageMediaMutationOutcome(conflict: true),
            default => throw new \RuntimeException('The Page Media repository returned an invalid outcome.'),
        };
    }
}

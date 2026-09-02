<?php

declare(strict_types=1);

namespace N3\Module\Media;

use Closure;
use LogicException;
use N3\App\Content\PageMediaAttachment;

final class LazyPageMediaRepository implements PageMediaRepository
{
    private ?PageMediaRepository $repository = null;

    public function __construct(private readonly Closure $factory)
    {
    }

    public function options(int $pageId): array { return $this->repository()->options($pageId); }
    public function attachment(int $pageId): ?PageMediaAttachment { return $this->repository()->attachment($pageId); }
    public function updateDraft(int $pageId, ?string $publicId, string $altText, int $actorId, int $expectedVersion, string $requestId): string
    {
        return $this->repository()->updateDraft($pageId, $publicId, $altText, $actorId, $expectedVersion, $requestId);
    }
    public function isPubliclyAttached(string $publicId): bool { return $this->repository()->isPubliclyAttached($publicId); }

    private function repository(): PageMediaRepository
    {
        if ($this->repository === null) {
            $repository = ($this->factory)();
            if (!$repository instanceof PageMediaRepository) {
                throw new LogicException('The Page Media repository factory returned an invalid service.');
            }
            $this->repository = $repository;
        }

        return $this->repository;
    }
}

<?php
declare(strict_types=1);

namespace N3\Service;

use N3\Repository\PageRepository;
use N3\Repository\SpaceRepository;

final class PageTreeService
{
    public function __construct(
        private readonly SpaceRepository $spaces,
        private readonly PageRepository $pages,
    ) {}

    public function validatePlacement(int $spaceId, ?int $parentId, ?int $pageId = null): void
    {
        if (!$this->spaces->exists($spaceId)) throw new DomainException('Space not found.', 404);
        if ($parentId === null) return;
        if ($pageId !== null && $parentId === $pageId) {
            throw new DomainException('A page cannot be its own parent.', 422);
        }
        if ($this->pages->parentSpace($parentId) !== $spaceId) {
            throw new DomainException('Parent page must be in the same space.', 422);
        }
        if ($pageId !== null && $this->pages->isDescendant($pageId, $parentId)) {
            throw new DomainException('A page cannot be moved beneath one of its descendants.', 422);
        }
    }

    public function reorder(int $sourceId, int $spaceId, ?int $parentId, array $orderedIds, ?int $actorId = null): void
    {
        if ($sourceId < 1 || $spaceId < 1 || !$orderedIds || count($orderedIds) !== count(array_unique($orderedIds))) {
            throw new DomainException('Invalid tree reorder payload.', 422);
        }
        $source = $this->pages->find($sourceId);
        if ($source === null) throw new DomainException('Page not found.', 404);
        $this->validatePlacement($spaceId, $parentId, $sourceId);
        if (!$this->pages->reorder($sourceId, (int)$source['space_id'], $spaceId, $parentId, $orderedIds, $actorId)) {
            throw new DomainException('The directory changed. Refresh and try the move again.', 409);
        }
    }
}

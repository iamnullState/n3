<?php
declare(strict_types=1);

namespace N3\Service;

use N3\Repository\PageRepository;
use N3\Repository\RevisionRepository;

final class RevisionRestoreService
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly RevisionRepository $revisions,
    ) {}

    public function restore(int $pageId, int $revision, int $baseRevision, ?int $actorId = null): array
    {
        $page = $this->pages->find($pageId);
        if ($page === null) throw new DomainException('Page not found.', 404);
        if ($page['kind'] !== 'page') throw new DomainException('Folders do not have revision history.', 422);
        if ($baseRevision < 1) throw new DomainException('A base revision is required to restore content.', 428);

        $snapshot = $this->revisions->snapshot($pageId, $revision);
        if ($snapshot === null) throw new DomainException('Revision not found.', 404);

        $restored = $this->revisions->restore($pageId, $snapshot, $baseRevision, $actorId);
        if ($restored === null) {
            throw new DomainException('This page changed in another session. Refresh history before restoring.', 409);
        }
        return [
            'ok' => true,
            'restored_from' => $revision,
            'updated_at' => $restored['updated_at'],
            'content_revision' => $restored['content_revision'],
        ];
    }
}

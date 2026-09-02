<?php

declare(strict_types=1);

namespace N3\App\Site;

interface SiteRepository
{
    public function identity(): ?SiteIdentity;

    /** @return list<NavigationItem> */
    public function publicNavigation(): array;

    /** @return list<NavigationItem> */
    public function administrationNavigation(): array;

    /** @param list<int> $pageIds */
    public function pageIdsExist(array $pageIds): bool;

    /** @param list<array{page_id: int, label: string, position: int, visible: bool}> $navigation */
    public function update(
        SiteIdentity $identity,
        array $navigation,
        int $actorId,
        int $expectedVersion,
        string $requestId,
    ): bool;

    public function scaffold(string $normalizedAdminEmail, string $requestId): ScaffoldOutcome;
}

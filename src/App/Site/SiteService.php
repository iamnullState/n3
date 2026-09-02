<?php

declare(strict_types=1);

namespace N3\App\Site;

final readonly class SiteService
{
    public function __construct(private SiteRepository $repository, private SiteValidator $validator)
    {
    }

    public function identity(): ?SiteIdentity
    {
        return $this->repository->identity();
    }

    /** @return list<NavigationItem> */
    public function publicNavigation(): array
    {
        return $this->repository->publicNavigation();
    }

    /** @return list<NavigationItem> */
    public function administrationNavigation(): array
    {
        return $this->repository->administrationNavigation();
    }

    public function update(
        string $name,
        string $tagline,
        string $email,
        string $color,
        string $logoPath,
        mixed $navigation,
        int $actorId,
        int $expectedVersion,
        string $requestId,
    ): SiteUpdateOutcome {
        $errors = $this->validator->identityErrors($name, $tagline, $email, $color, $logoPath);
        $parsed = $this->validator->navigation($navigation);
        $errors += $parsed['errors'];
        if ($parsed['items'] !== [] && !$this->repository->pageIdsExist(array_column($parsed['items'], 'page_id'))) {
            $errors['navigation'] = 'Navigation contains a Page that is no longer available.';
        }
        if ($errors !== []) {
            return new SiteUpdateOutcome($errors);
        }
        $identity = new SiteIdentity(
            trim($name), trim($tagline), mb_strtolower(trim($email), 'UTF-8'), strtoupper(trim($color)),
            trim($logoPath), $expectedVersion,
        );
        $updated = $this->repository->update($identity, $parsed['items'], $actorId, $expectedVersion, $requestId);

        return new SiteUpdateOutcome(conflict: !$updated);
    }

    public function scaffold(string $adminEmail, string $requestId = ''): ScaffoldOutcome
    {
        $email = mb_strtolower(trim($adminEmail), 'UTF-8');
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('A valid --admin-email address is required.');
        }

        return $this->repository->scaffold($email, $requestId);
    }
}

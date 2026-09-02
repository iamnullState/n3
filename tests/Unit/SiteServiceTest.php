<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\App\Site\NavigationItem;
use N3\App\Site\ScaffoldOutcome;
use N3\App\Site\SiteIdentity;
use N3\App\Site\SiteRepository;
use N3\App\Site\SiteService;
use N3\App\Site\SiteValidator;
use PHPUnit\Framework\TestCase;

final class SiteServiceTest extends TestCase
{
    public function testIdentityAndNavigationAreNormalizedBeforePersistence(): void
    {
        $repository = new InMemorySiteRepository([7, 9]);
        $service = new SiteService($repository, new SiteValidator());

        $outcome = $service->update(
            '  Example Site  ', '  A useful tagline  ', ' OWNER@Example.Test ', '#173f8f',
            '/assets/svg/brand/logo.svg', [
                '7' => ['label' => ' Home ', 'position' => '10', 'visible' => '1'],
                '9' => ['label' => 'About', 'position' => '20'],
            ], 42, 3, '0123456789abcdef',
        );

        self::assertTrue($outcome->succeeded());
        self::assertSame('Example Site', $repository->savedIdentity?->name);
        self::assertSame('owner@example.test', $repository->savedIdentity?->contactEmail);
        self::assertSame('#173F8F', $repository->savedIdentity?->primaryColor);
        self::assertSame('Home', $repository->savedNavigation[0]['label']);
        self::assertTrue($repository->savedNavigation[0]['visible']);
        self::assertFalse($repository->savedNavigation[1]['visible']);
    }

    public function testInvalidAndTamperedSettingsNeverReachTheRepository(): void
    {
        $repository = new InMemorySiteRepository([7]);
        $service = new SiteService($repository, new SiteValidator());

        $outcome = $service->update(
            '<script>', "unsafe\0tagline", 'not-email', '#FFFFFF', 'https://evil.test/logo.svg',
            ['999' => ['label' => 'Missing page', 'position' => '10', 'visible' => '1']],
            42, 1, '0123456789abcdef',
        );

        self::assertFalse($outcome->succeeded());
        self::assertArrayHasKey('tagline', $outcome->errors);
        self::assertArrayHasKey('contact_email', $outcome->errors);
        self::assertArrayHasKey('primary_color', $outcome->errors);
        self::assertArrayHasKey('logo_path', $outcome->errors);
        self::assertArrayHasKey('navigation', $outcome->errors);
        self::assertNull($repository->savedIdentity);
        self::assertArrayHasKey('logo_path', (new SiteValidator())->identityErrors(
            'Safe Site', '', 'owner@example.test', '#173F8F', '/assets/svg/logo.php',
        ));
    }

    public function testNavigationRequiresUniqueBoundedPositionsAndValidRows(): void
    {
        $validator = new SiteValidator();
        $result = $validator->navigation([
            '7' => ['label' => '', 'position' => '10'],
            '9' => ['label' => 'About', 'position' => '10'],
            '11' => ['label' => 'Contact', 'position' => '70000'],
        ]);

        self::assertNotEmpty($result['errors']);
        self::assertArrayHasKey('navigation_7', $result['errors']);
        self::assertArrayHasKey('navigation_9', $result['errors']);
        self::assertArrayHasKey('navigation_11', $result['errors']);
    }

    public function testScaffoldNormalizesEmailAndRejectsInvalidInput(): void
    {
        $repository = new InMemorySiteRepository([]);
        $service = new SiteService($repository, new SiteValidator());

        $outcome = $service->scaffold(' ADMIN@Example.Test ', '0123456789abcdef');
        self::assertSame(5, $outcome->createdPages);
        self::assertSame('admin@example.test', $repository->scaffoldEmail);

        $this->expectException(\InvalidArgumentException::class);
        $service->scaffold('invalid');
    }

    public function testRepositoryConflictIsReportedWithoutErrors(): void
    {
        $repository = new InMemorySiteRepository([]);
        $repository->updateResult = false;
        $service = new SiteService($repository, new SiteValidator());

        $outcome = $service->update(
            'Example Site', '', 'owner@example.test', '#173F8F', '', [], 42, 1, '',
        );

        self::assertTrue($outcome->conflict);
        self::assertSame([], $outcome->errors);
    }
}

final class InMemorySiteRepository implements SiteRepository
{
    public ?SiteIdentity $savedIdentity = null;
    /** @var list<array{page_id: int, label: string, position: int, visible: bool}> */
    public array $savedNavigation = [];
    public bool $updateResult = true;
    public string $scaffoldEmail = '';

    /** @param list<int> $existingPageIds */
    public function __construct(private readonly array $existingPageIds)
    {
    }

    public function identity(): ?SiteIdentity
    {
        return $this->savedIdentity;
    }

    public function publicNavigation(): array
    {
        return [];
    }

    public function administrationNavigation(): array
    {
        return [];
    }

    public function pageIdsExist(array $pageIds): bool
    {
        return array_diff($pageIds, $this->existingPageIds) === [];
    }

    public function update(SiteIdentity $identity, array $navigation, int $actorId, int $expectedVersion, string $requestId): bool
    {
        $this->savedIdentity = $identity;
        $this->savedNavigation = $navigation;

        return $this->updateResult;
    }

    public function scaffold(string $normalizedAdminEmail, string $requestId): ScaffoldOutcome
    {
        $this->scaffoldEmail = $normalizedAdminEmail;

        return new ScaffoldOutcome(5, 0, true);
    }
}

<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\App\Content\PageMediaAttachment;
use N3\App\Content\PageMediaOption;
use N3\Module\Media\PageMediaRepository;
use N3\Module\Media\PageMediaService;
use PHPUnit\Framework\TestCase;

final class PageMediaServiceTest extends TestCase
{
    public function testAttachmentRequiresAValidLibraryIdAndMeaningfulUtf8AltText(): void
    {
        $repository = new StubPageMediaRepository();
        $service = new PageMediaService($repository);

        self::assertArrayHasKey('media', $service->updateDraft(1, 'not-an-id', 'Description', 2, 1, str_repeat('a', 16))->errors);
        self::assertArrayHasKey('alt_text', $service->updateDraft(1, str_repeat('b', 32), '', 2, 1, str_repeat('a', 16))->errors);
        self::assertArrayHasKey('alt_text', $service->updateDraft(1, str_repeat('b', 32), "bad\xFFtext", 2, 1, str_repeat('a', 16))->errors);
        self::assertSame(0, $repository->updates);
    }

    public function testAttachNormalizesInputAndMapsRepositoryOutcomes(): void
    {
        $repository = new StubPageMediaRepository();
        $service = new PageMediaService($repository);
        $id = str_repeat('b', 32);

        $attached = $service->updateDraft(4, ' ' . $id . ' ', '  A blue mountain at sunrise  ', 7, 3, str_repeat('c', 16));
        self::assertTrue($attached->succeeded());
        self::assertSame([$id, 'A blue mountain at sunrise'], $repository->lastMedia);

        $repository->outcome = 'conflict';
        self::assertTrue($service->updateDraft(4, $id, 'Description', 7, 3, str_repeat('c', 16))->conflict);
        $repository->outcome = 'missing_asset';
        self::assertArrayHasKey('media', $service->updateDraft(4, $id, 'Description', 7, 3, str_repeat('c', 16))->errors);
    }

    public function testDetachNeedsNoAlternativeText(): void
    {
        $repository = new StubPageMediaRepository();
        $service = new PageMediaService($repository);
        self::assertTrue($service->updateDraft(4, '', '', 7, 3, str_repeat('d', 16))->succeeded());
        self::assertSame([null, ''], $repository->lastMedia);
    }
}

final class StubPageMediaRepository implements PageMediaRepository
{
    public int $updates = 0;
    public string $outcome = 'attached';
    /** @var array{string|null, string}|null */
    public ?array $lastMedia = null;

    public function options(int $pageId): array { return [new PageMediaOption(str_repeat('b', 32), 'Image', 10, 10)]; }
    public function attachment(int $pageId): ?PageMediaAttachment { return null; }
    public function updateDraft(int $pageId, ?string $publicId, string $altText, int $actorId, int $expectedVersion, string $requestId): string
    {
        $this->updates++;
        $this->lastMedia = [$publicId, $altText];
        return $this->outcome;
    }
    public function isPubliclyAttached(string $publicId): bool { return false; }
}

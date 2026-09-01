<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use DateTimeImmutable;
use N3\Core\Http\UploadedFile;
use N3\Core\Storage\ScopedModuleStorage;
use N3\Module\Media\ImageProcessor;
use N3\Module\Media\MediaAsset;
use N3\Module\Media\MediaConfig;
use N3\Module\Media\MediaRepository;
use N3\Module\Media\MediaService;
use N3\Module\Media\ProcessedImage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MediaServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/n3-media-service-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testSuccessfulUploadWritesOnlyProcessedFilesAndCatalogData(): void
    {
        $repository = new InMemoryMediaRepository();
        $service = $this->service($repository);
        $source = $this->sourceFile('untrusted source bytes');

        $outcome = $service->upload('  Homepage image  ', $source, '203.0.113.7', 1_788_278_400);

        self::assertTrue($outcome->succeeded());
        self::assertNotNull($outcome->asset);
        self::assertSame('Homepage image', $outcome->asset->label);
        self::assertSame(640, $outcome->asset->width);
        self::assertSame(480, $outcome->asset->height);
        self::assertSame(['203.0.113.7'], $repository->rateSubjects);
        self::assertSame(['upload_succeeded'], $repository->events);

        $master = $this->masters()->read(MediaService::masterPath($outcome->asset->publicId));
        $preview = $this->previews()->read(MediaService::previewPath($outcome->asset->publicId));
        self::assertSame(FakeImageProcessor::MASTER, $master);
        self::assertSame(FakeImageProcessor::PREVIEW, $preview);
        self::assertStringNotContainsString('untrusted source bytes', (string) $master . (string) $preview);
    }

    public function testValidationAndRateLimitUseGenericControlledEvents(): void
    {
        $repository = new InMemoryMediaRepository();
        $service = $this->service($repository);

        $invalid = $service->upload("x\n", null, '198.51.100.4', 1_788_278_400);
        self::assertSame(['label' => 'Use a label between 2 and 120 characters without control characters.'], $invalid->errors);
        self::assertSame(['upload_rejected'], $repository->events);

        $invalidUtf8 = $service->upload("bad\xFFlabel", null, '198.51.100.4', 1_788_278_400);
        self::assertArrayHasKey('label', $invalidUtf8->errors);

        $repository->allow = false;
        $limited = $service->upload('Valid label', $this->sourceFile('bytes'), '198.51.100.4', 1_788_278_401);
        self::assertTrue($limited->rateLimited);
        self::assertSame(['upload_rejected', 'upload_rejected', 'upload_rate_limited'], $repository->events);
    }

    public function testCatalogFailureRemovesBothProcessedFiles(): void
    {
        $repository = new InMemoryMediaRepository();
        $repository->failCreate = true;
        $service = $this->service($repository);

        try {
            $service->upload('Valid label', $this->sourceFile('bytes'), '192.0.2.9', 1_788_278_400);
            self::fail('Expected the repository failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('catalog unavailable', $exception->getMessage());
        }

        self::assertSame([], glob($this->root . '/n3/media/{data,cache}/*/*/*.webp', GLOB_BRACE));
    }

    public function testPreviewRequiresCatalogEntryAndReturnsPrivateDerivative(): void
    {
        $repository = new InMemoryMediaRepository();
        $service = $this->service($repository);
        self::assertNull($service->preview(str_repeat('a', 32)));

        $outcome = $service->upload('Preview image', $this->sourceFile('bytes'), '192.0.2.8', 1_788_278_400);
        $preview = $service->preview($outcome->asset?->publicId ?? '');
        self::assertNotNull($preview);
        self::assertSame(FakeImageProcessor::PREVIEW, $preview->contents);
        self::assertSame(hash('sha256', FakeImageProcessor::PREVIEW), $preview->etag);
    }

    private function service(InMemoryMediaRepository $repository): MediaService
    {
        return new MediaService($repository, new FakeImageProcessor(), $this->masters(), $this->previews(), $this->config());
    }

    private function masters(): ScopedModuleStorage
    {
        return new ScopedModuleStorage($this->root, 'n3/media', 'data', 12_582_912);
    }

    private function previews(): ScopedModuleStorage
    {
        return new ScopedModuleStorage($this->root, 'n3/media', 'cache', 1_048_576);
    }

    private function config(): MediaConfig
    {
        return new MediaConfig(10_485_760, 25_000_000, 12_000, 12_582_912, 480, 20, 85, 78, str_repeat('k', 32));
    }

    private function sourceFile(string $contents): UploadedFile
    {
        $path = tempnam($this->root, 'source-');
        self::assertNotFalse($path);
        self::assertNotFalse(file_put_contents($path, $contents));

        return new UploadedFile($path, UPLOAD_ERR_OK, strlen($contents), false);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

final class FakeImageProcessor implements ImageProcessor
{
    public const MASTER = "RIFF\x04\x00\x00\x00WEBPmaster";
    public const PREVIEW = "RIFF\x04\x00\x00\x00WEBPpreview";

    public function process(UploadedFile $file): ProcessedImage
    {
        return new ProcessedImage(self::MASTER, self::PREVIEW, 640, 480);
    }
}

final class InMemoryMediaRepository implements MediaRepository
{
    /** @var array<string, MediaAsset> */
    public array $assets = [];
    /** @var list<string> */
    public array $events = [];
    /** @var list<string> */
    public array $rateSubjects = [];
    public bool $allow = true;
    public bool $failCreate = false;

    public function list(int $limit): array
    {
        return array_slice(array_values($this->assets), 0, $limit);
    }

    public function find(string $publicId): ?MediaAsset
    {
        return $this->assets[$publicId] ?? null;
    }

    public function create(MediaAsset $asset): void
    {
        if ($this->failCreate) {
            throw new RuntimeException('catalog unavailable');
        }
        $this->assets[$asset->publicId] = $asset;
        $this->events[] = 'upload_succeeded';
    }

    public function allowUpload(string $subject, int $now, int $limit): bool
    {
        $this->rateSubjects[] = $subject;
        return $this->allow;
    }

    public function recordEvent(string $eventKey, ?string $assetPublicId = null): void
    {
        $this->events[] = $eventKey;
    }
}

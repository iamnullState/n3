<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\App\Content\PageMediaAttachment;
use N3\Core\Http\Request;
use N3\Core\Logging\FileLogger;
use N3\Core\Storage\ScopedModuleStorage;
use N3\Module\Media\MediaService;
use N3\Module\Media\PageMediaRepository;
use N3\Module\Media\PublicMediaController;
use N3\Module\Media\PublicMediaService;
use PHPUnit\Framework\TestCase;

final class PublicMediaControllerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/n3-public-media-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
        rmdir($this->root);
    }

    public function testOnlyPublishedAttachmentsReceiveTheSanitizedDerivative(): void
    {
        $id = str_repeat('a', 32);
        $repository = new DeliveryPageMediaRepository();
        [$controller, $storage] = $this->controller($repository);
        $storage->put(MediaService::previewPath($id), "RIFF\x04\x00\x00\x00WEBPpreview");
        $request = Request::create('GET', '/media/' . $id . '.webp')->withAttribute('route_parameters', ['id' => $id]);

        self::assertSame(404, $controller->show($request)->status());
        $repository->public = true;
        $response = $controller->show($request);
        self::assertSame(200, $response->status());
        self::assertSame('image/webp', $response->headers()['Content-Type']);
        self::assertSame('public, max-age=300', $response->headers()['Cache-Control']);
        self::assertSame("RIFF\x04\x00\x00\x00WEBPpreview", $response->body());

        $cached = $controller->show(Request::create('GET', '/media/' . $id . '.webp', server: [
            'HTTP_IF_NONE_MATCH' => $response->headers()['ETag'],
        ])->withAttribute('route_parameters', ['id' => $id]));
        self::assertSame(304, $cached->status());
        self::assertSame('', $cached->body());
    }

    public function testMissingAttachedFileFailsSafelyWithoutLeakingItsIdentifier(): void
    {
        $repository = new DeliveryPageMediaRepository();
        $repository->public = true;
        [$controller] = $this->controller($repository);
        $id = str_repeat('f', 32);
        $response = $controller->show(Request::create('GET', '/media/' . $id . '.webp')->withAttribute('route_parameters', ['id' => $id]));
        self::assertSame(503, $response->status());
        self::assertSame('', $response->body());
        $log = (string) file_get_contents($this->root . '/app.log');
        self::assertStringContainsString('public_media_delivery_failed', $log);
        self::assertStringNotContainsString($id, $log);
    }

    /** @return array{PublicMediaController, ScopedModuleStorage} */
    private function controller(DeliveryPageMediaRepository $repository): array
    {
        $storage = new ScopedModuleStorage($this->root, 'n3/media', 'cache');
        return [new PublicMediaController(new PublicMediaService($repository, $storage), new FileLogger($this->root . '/app.log')), $storage];
    }
}

final class DeliveryPageMediaRepository implements PageMediaRepository
{
    public bool $public = false;
    public function options(int $pageId): array { return []; }
    public function attachment(int $pageId): ?PageMediaAttachment { return null; }
    public function updateDraft(int $pageId, ?string $publicId, string $altText, int $actorId, int $expectedVersion, string $requestId): string { return 'unchanged'; }
    public function isPubliclyAttached(string $publicId): bool { return $this->public; }
}

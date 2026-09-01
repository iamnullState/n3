<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use DateTimeImmutable;
use N3\Core\Http\Request;
use N3\Core\Http\UploadedFile;
use N3\Core\Logging\FileLogger;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Security\CurrentPrincipal;
use N3\Core\Security\CurrentPrincipalProvider;
use N3\Core\Session\ArraySessionStore;
use N3\Core\Session\FlashBag;
use N3\Core\Storage\ScopedModuleStorage;
use N3\Core\View\View;
use N3\Module\Media\ImageProcessor;
use N3\Module\Media\MediaAsset;
use N3\Module\Media\MediaConfig;
use N3\Module\Media\MediaController;
use N3\Module\Media\MediaRepository;
use N3\Module\Media\MediaService;
use N3\Module\Media\ProcessedImage;
use PHPUnit\Framework\TestCase;

final class MediaControllerTest extends TestCase
{
    private string $root;
    private string $log;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/n3-media-controller-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
        $this->log = $this->root . '/app.log';
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testAnonymousAndMemberUsersCannotReadTheLibrary(): void
    {
        [$anonymous] = $this->controller(null);
        self::assertSame(303, $anonymous->index(Request::create('GET', '/admin/media'))->status());
        self::assertSame('/login', $anonymous->index(Request::create('GET', '/admin/media'))->headers()['Location']);

        [$member] = $this->controller(new CurrentPrincipal('member'));
        $response = $member->index(Request::create('GET', '/admin/media'));
        self::assertSame(403, $response->status());
        self::assertSame('no-store', $response->headers()['Cache-Control']);
    }

    public function testAdminLibraryIsPrivateAndEscapesHostileCatalogLabels(): void
    {
        $repository = new ControllerMediaRepository();
        $repository->assets[str_repeat('a', 32)] = new MediaAsset(
            str_repeat('a', 32), '<img src=x onerror=alert(1)>', 10, 10, 100, str_repeat('b', 64), new DateTimeImmutable('2026-09-01T12:00:00Z'),
        );
        [$controller] = $this->controller(new CurrentPrincipal('admin'), $repository);

        $response = $controller->index(Request::create('GET', '/admin/media'));
        self::assertSame(200, $response->status());
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertSame('noindex, nofollow', $response->headers()['X-Robots-Tag']);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $response->body());
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $response->body());
    }

    public function testUploadRequiresCsrfAndSuccessfulPreviewRemainsAuthenticatedAndPrivate(): void
    {
        $repository = new ControllerMediaRepository();
        [$controller, $csrf] = $this->controller(new CurrentPrincipal('admin'), $repository);
        $invalid = $controller->upload(Request::create('POST', '/admin/media', ['label' => 'Safe image', '_csrf' => 'wrong']));
        self::assertSame(419, $invalid->status());

        $source = tempnam($this->root, 'upload-');
        self::assertNotFalse($source);
        file_put_contents($source, 'raw source');
        $request = Request::create(
            'POST', '/admin/media', ['label' => 'Safe image', '_csrf' => $csrf->token('media_upload')], ['REMOTE_ADDR' => '203.0.113.12'], [], '',
            ['image' => new UploadedFile($source, UPLOAD_ERR_OK, 10, false)],
        );
        $uploaded = $controller->upload($request);
        self::assertSame(303, $uploaded->status());
        self::assertSame('/admin/media', $uploaded->headers()['Location']);

        $id = array_key_first($repository->assets);
        self::assertIsString($id);
        $preview = $controller->preview(Request::create('GET', '/admin/media/' . $id . '/preview')->withAttribute('route_parameters', ['id' => $id]));
        self::assertSame(200, $preview->status());
        self::assertSame('image/webp', $preview->headers()['Content-Type']);
        self::assertSame('private, max-age=300', $preview->headers()['Cache-Control']);

        [$anonymous] = $this->controller(null, $repository);
        self::assertSame(303, $anonymous->preview(Request::create('GET', '/admin/media/' . $id . '/preview')->withAttribute('route_parameters', ['id' => $id]))->status());
    }

    /** @return array{MediaController, CsrfTokenManager} */
    private function controller(?CurrentPrincipal $principal, ?ControllerMediaRepository $repository = null): array
    {
        $repository ??= new ControllerMediaRepository();
        $session = new ArraySessionStore();
        $csrf = new CsrfTokenManager($session);
        $service = new MediaService(
            $repository,
            new ControllerImageProcessor(),
            new ScopedModuleStorage($this->root, 'n3/media', 'data', 12_582_912),
            new ScopedModuleStorage($this->root, 'n3/media', 'cache', 1_048_576),
            new MediaConfig(10_485_760, 25_000_000, 12_000, 12_582_912, 480, 20, 85, 78, str_repeat('k', 32)),
        );
        return [new MediaController(
            new View(dirname(__DIR__, 2) . '/resources/views'),
            new ControllerPrincipalProvider($principal),
            $service,
            $csrf,
            new FlashBag($session),
            new FileLogger($this->log),
        ), $csrf];
    }
}

final readonly class ControllerPrincipalProvider implements CurrentPrincipalProvider
{
    public function __construct(private ?CurrentPrincipal $principal) {}
    public function current(): ?CurrentPrincipal { return $this->principal; }
}

final class ControllerImageProcessor implements ImageProcessor
{
    public const MASTER = "RIFF\x04\x00\x00\x00WEBPmaster";
    public const PREVIEW = "RIFF\x04\x00\x00\x00WEBPpreview";
    public function process(UploadedFile $file): ProcessedImage { return new ProcessedImage(self::MASTER, self::PREVIEW, 16, 12); }
}

final class ControllerMediaRepository implements MediaRepository
{
    /** @var array<string, MediaAsset> */
    public array $assets = [];
    public function list(int $limit): array { return array_slice(array_values($this->assets), 0, $limit); }
    public function find(string $publicId): ?MediaAsset { return $this->assets[$publicId] ?? null; }
    public function create(MediaAsset $asset): void { $this->assets[$asset->publicId] = $asset; }
    public function allowUpload(string $subject, int $now, int $limit): bool { return true; }
    public function recordEvent(string $eventKey, ?string $assetPublicId = null): void {}
}

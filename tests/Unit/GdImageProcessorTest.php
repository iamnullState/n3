<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Http\UploadedFile;
use N3\Module\Media\GdImageProcessor;
use N3\Module\Media\MediaConfig;
use N3\Module\Media\MediaUploadRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GdImageProcessorTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        if (!GdImageProcessor::available()) {
            $this->markTestSkipped('GD with JPEG, PNG, and WebP support is not installed.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[DataProvider('imageTypes')]
    public function testJpegAndPngAreDecodedAndReencodedAsBoundedWebp(string $type): void
    {
        $path = $this->image($type, 800, 600);
        $processed = (new GdImageProcessor($this->config()))->process($this->upload($path));

        self::assertSame(800, $processed->width);
        self::assertSame(600, $processed->height);
        self::assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($processed->master));
        self::assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($processed->preview));
        $preview = getimagesizefromstring($processed->preview);
        self::assertIsArray($preview);
        self::assertSame(480, $preview[0]);
        self::assertSame(360, $preview[1]);
    }

    /** @return iterable<string, array{string}> */
    public static function imageTypes(): iterable
    {
        yield 'JPEG' => ['jpeg'];
        yield 'PNG' => ['png'];
    }

    public function testTrailingPolyglotPayloadIsRejected(): void
    {
        $path = $this->image('png', 32, 32);
        file_put_contents($path, '<script>private-payload</script>', FILE_APPEND);

        $this->expectException(MediaUploadRejected::class);
        (new GdImageProcessor($this->config()))->process($this->upload($path));
    }

    public function testUnsupportedSvgIsRejectedWithoutDecoding(): void
    {
        $path = $this->temporary('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->expectException(MediaUploadRejected::class);
        (new GdImageProcessor($this->config()))->process($this->upload($path));
    }

    public function testPixelBombIsRejectedBeforeFullProcessing(): void
    {
        $path = $this->image('png', 1_100, 1_000);

        $this->expectException(MediaUploadRejected::class);
        $this->expectExceptionMessage('dimensions exceed');
        (new GdImageProcessor($this->config(maximumPixels: 1_000_000)))->process($this->upload($path));
    }

    private function image(string $type, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        $color = imagecolorallocate($image, 25, 90, 160);
        imagefill($image, 0, 0, $color);
        ob_start();
        try {
            $ok = $type === 'jpeg' ? imagejpeg($image, null, 85) : imagepng($image);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertTrue($ok);
        self::assertIsString($bytes);

        return $this->temporary($bytes);
    }

    private function temporary(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'n3-media-image-');
        self::assertNotFalse($path);
        self::assertNotFalse(file_put_contents($path, $contents));
        $this->temporaryFiles[] = $path;
        return $path;
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile($path, UPLOAD_ERR_OK, (int) filesize($path), false);
    }

    private function config(int $maximumPixels = 25_000_000): MediaConfig
    {
        return new MediaConfig(10_485_760, $maximumPixels, 12_000, 12_582_912, 480, 20, 85, 78, str_repeat('k', 32));
    }
}

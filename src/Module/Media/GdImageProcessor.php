<?php

declare(strict_types=1);

namespace N3\Module\Media;

use finfo;
use N3\Core\Http\UploadedFile;
use Throwable;

final readonly class GdImageProcessor implements ImageProcessor
{
    public function __construct(private MediaConfig $config)
    {
        if (!self::available()) {
            throw new \RuntimeException('The enabled Media module requires GD with JPEG, PNG, and WebP support plus fileinfo.');
        }
    }

    public static function available(): bool
    {
        return extension_loaded('gd') && extension_loaded('fileinfo')
            && function_exists('imagecreatefromstring') && function_exists('imagewebp')
            && function_exists('imagecopyresampled');
    }

    public function process(UploadedFile $file): ProcessedImage
    {
        if (!$file->isReadableUpload()) {
            throw new MediaUploadRejected('upload_failed', $file->error === UPLOAD_ERR_NO_FILE
                ? 'Choose a JPEG or PNG image.'
                : 'The image upload failed or exceeded server limits.');
        }

        $size = filesize($file->temporaryPath);
        if (!is_int($size) || $size < 1 || $size > $this->config->maximumUploadBytes) {
            throw new MediaUploadRejected('file_size', 'The image must be no larger than the configured upload limit.');
        }

        $bytes = file_get_contents($file->temporaryPath);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            throw new MediaUploadRejected('unreadable', 'The image could not be read safely.');
        }

        try {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $info = getimagesizefromstring($bytes);
        } catch (Throwable) {
            throw new MediaUploadRejected('invalid_image', 'The file is not a valid JPEG or PNG image.');
        }
        if (!is_string($mime) || !is_array($info) || !isset($info[0], $info[1], $info[2])) {
            throw new MediaUploadRejected('invalid_image', 'The file is not a valid JPEG or PNG image.');
        }

        $expectedType = match ($mime) {
            'image/jpeg' => IMAGETYPE_JPEG,
            'image/png' => IMAGETYPE_PNG,
            default => null,
        };
        if ($expectedType === null || (int) $info[2] !== $expectedType || !$this->strictContainer($bytes, $expectedType)) {
            throw new MediaUploadRejected('unsupported_type', 'Only valid JPEG and PNG images are accepted.');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 1 || $height < 1 || $width > $this->config->maximumDimension
            || $height > $this->config->maximumDimension || $width * $height > $this->config->maximumPixels) {
            throw new MediaUploadRejected('dimensions', 'The image dimensions exceed the configured safety limit.');
        }

        try {
            $image = imagecreatefromstring($bytes);
        } catch (Throwable) {
            $image = false;
        }
        if ($image === false) {
            throw new MediaUploadRejected('decode_failed', 'The image could not be decoded safely.');
        }

        try {
            if ($expectedType === IMAGETYPE_JPEG) {
                $image = $this->orient($image, $file->temporaryPath);
            }
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
            $width = imagesx($image);
            $height = imagesy($image);
            $master = $this->encode($image, $this->config->webpQuality);

            $scale = min(1, $this->config->previewMaximumDimension / max($width, $height));
            $previewWidth = max(1, (int) round($width * $scale));
            $previewHeight = max(1, (int) round($height * $scale));
            $previewImage = imagecreatetruecolor($previewWidth, $previewHeight);
            if ($previewImage === false) {
                throw new \RuntimeException('Unable to allocate the Media preview image.');
            }
            imagealphablending($previewImage, false);
            imagesavealpha($previewImage, true);
            if (!imagecopyresampled($previewImage, $image, 0, 0, 0, 0, $previewWidth, $previewHeight, $width, $height)) {
                throw new \RuntimeException('Unable to resize the Media preview image.');
            }
            $preview = $this->encode($previewImage, $this->config->previewWebpQuality);
        } catch (MediaUploadRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaUploadRejected('processing_failed', 'The image could not be processed safely.');
        }

        if (strlen($master) > $this->config->maximumProcessedBytes || strlen($preview) > 1_048_576) {
            throw new MediaUploadRejected('processed_size', 'The processed image exceeds the configured storage limit.');
        }

        return new ProcessedImage($master, $preview, $width, $height);
    }

    public function regeneratePreview(string $master): string
    {
        if ($master === '' || strlen($master) > $this->config->maximumProcessedBytes) {
            throw new MediaUploadRejected('master_invalid', 'The sanitized master cannot be regenerated safely.');
        }
        try {
            $image = imagecreatefromstring($master);
        } catch (Throwable) {
            $image = false;
        }
        if ($image === false) {
            throw new MediaUploadRejected('master_invalid', 'The sanitized master cannot be regenerated safely.');
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            if ($width < 1 || $height < 1 || $width > $this->config->maximumDimension
                || $height > $this->config->maximumDimension || $width * $height > $this->config->maximumPixels) {
                throw new \RuntimeException('Sanitized master dimensions are invalid.');
            }
            $scale = min(1, $this->config->previewMaximumDimension / max($width, $height));
            $previewWidth = max(1, (int) round($width * $scale));
            $previewHeight = max(1, (int) round($height * $scale));
            $previewImage = imagecreatetruecolor($previewWidth, $previewHeight);
            if ($previewImage === false) {
                throw new \RuntimeException('Unable to allocate the Media preview image.');
            }
            imagealphablending($previewImage, false);
            imagesavealpha($previewImage, true);
            if (!imagecopyresampled($previewImage, $image, 0, 0, 0, 0, $previewWidth, $previewHeight, $width, $height)) {
                throw new \RuntimeException('Unable to resize the Media preview image.');
            }
            $preview = $this->encode($previewImage, $this->config->previewWebpQuality);
        } catch (Throwable) {
            throw new MediaUploadRejected('regeneration_failed', 'The sanitized master cannot be regenerated safely.');
        }
        if (strlen($preview) > 1_048_576) {
            throw new MediaUploadRejected('processed_size', 'The regenerated preview exceeds its storage limit.');
        }

        return $preview;
    }

    private function encode(object $image, int $quality): string
    {
        $contents = null;
        ob_start();
        try {
            if (!imagewebp($image, null, $quality)) {
                throw new \RuntimeException('Unable to encode WebP image data.');
            }
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        if (!is_string($contents) || $contents === '') {
            throw new \RuntimeException('WebP encoding returned no image data.');
        }

        return $contents;
    }

    private function orient(object $image, string $path): object
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        try {
            $metadata = exif_read_data($path, 'IFD0', true, false);
            $orientation = is_array($metadata) ? (int) ($metadata['IFD0']['Orientation'] ?? 1) : 1;
        } catch (Throwable) {
            return $image;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            $mode = in_array($orientation, [2, 5], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL;
            if (!imageflip($image, $mode)) {
                throw new \RuntimeException('Unable to orient uploaded image pixels.');
            }
        }
        $angle = match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated === false) {
                throw new \RuntimeException('Unable to rotate uploaded image pixels.');
            }
            $image = $rotated;
        }

        return $image;
    }

    private function strictContainer(string $bytes, int $type): bool
    {
        if ($type === IMAGETYPE_JPEG) {
            return str_starts_with($bytes, "\xFF\xD8") && str_ends_with($bytes, "\xFF\xD9");
        }
        if (!str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return false;
        }

        $offset = 8;
        $length = strlen($bytes);
        while ($offset + 12 <= $length) {
            $unpacked = unpack('Nlength', substr($bytes, $offset, 4));
            $chunkLength = is_array($unpacked) ? (int) $unpacked['length'] : -1;
            $chunkType = substr($bytes, $offset + 4, 4);
            if ($chunkLength < 0 || $offset + 12 + $chunkLength > $length) {
                return false;
            }
            $offset += 12 + $chunkLength;
            if ($chunkType === 'IEND') {
                return $chunkLength === 0 && $offset === $length;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace N3\Module\Media;

use DateTimeImmutable;
use DateTimeZone;
use N3\Core\Http\UploadedFile;
use N3\Core\Storage\ScopedModuleStorage;
use Throwable;

final readonly class MediaService
{
    public function __construct(
        private MediaRepository $repository,
        private ImageProcessor $processor,
        private ScopedModuleStorage $masters,
        private ScopedModuleStorage $previews,
        private MediaConfig $config,
    ) {
    }

    /** @return list<MediaAsset> */
    public function list(): array
    {
        return $this->repository->list(100);
    }

    public function upload(string $label, ?UploadedFile $file, string $clientIp, ?int $now = null): MediaUploadOutcome
    {
        $now ??= time();
        if (!$this->repository->allowUpload($clientIp, $now, $this->config->uploadAttemptsPerHour)) {
            $this->repository->recordEvent('upload_rate_limited');
            return new MediaUploadOutcome(rateLimited: true);
        }

        $label = trim($label);
        if (!mb_check_encoding($label, 'UTF-8') || mb_strlen($label) < 2 || mb_strlen($label) > 120
            || preg_match('/[\x00-\x1F\x7F]/u', $label) === 1) {
            $this->repository->recordEvent('upload_rejected');
            return new MediaUploadOutcome(errors: ['label' => 'Use a label between 2 and 120 characters without control characters.']);
        }
        if ($file === null) {
            $this->repository->recordEvent('upload_rejected');
            return new MediaUploadOutcome(errors: ['image' => 'Choose a JPEG or PNG image.']);
        }

        try {
            $processed = $this->processor->process($file);
        } catch (MediaUploadRejected $exception) {
            $this->repository->recordEvent('upload_rejected');
            return new MediaUploadOutcome(errors: ['image' => $exception->publicMessage]);
        }

        $publicId = bin2hex(random_bytes(16));
        $masterPath = self::masterPath($publicId);
        $previewPath = self::previewPath($publicId);
        try {
            $this->masters->put($masterPath, $processed->master);
            $this->previews->put($previewPath, $processed->preview);
            $asset = new MediaAsset(
                $publicId,
                $label,
                $processed->width,
                $processed->height,
                strlen($processed->master),
                hash('sha256', $processed->master),
                (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('UTC')),
            );
            $this->repository->create($asset);
        } catch (Throwable $exception) {
            $this->safeDelete($this->previews, $previewPath);
            $this->safeDelete($this->masters, $masterPath);
            throw $exception;
        }

        return new MediaUploadOutcome(asset: $asset);
    }

    public function preview(string $publicId): ?MediaPreview
    {
        $asset = $this->repository->find($publicId);
        if ($asset === null) {
            return null;
        }
        $contents = $this->previews->read(self::previewPath($publicId));
        if ($contents === null) {
            throw new \RuntimeException('The cataloged Media preview is unavailable.');
        }

        return new MediaPreview($contents, hash('sha256', $contents));
    }

    public static function masterPath(string $publicId): string
    {
        self::assertPublicId($publicId);
        return 'assets/' . substr($publicId, 0, 2) . '/' . $publicId . '.webp';
    }

    public static function previewPath(string $publicId): string
    {
        self::assertPublicId($publicId);
        return 'previews/' . substr($publicId, 0, 2) . '/' . $publicId . '.webp';
    }

    private static function assertPublicId(string $publicId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            throw new \InvalidArgumentException('Media public identifiers are invalid.');
        }
    }

    private function safeDelete(ScopedModuleStorage $storage, string $path): void
    {
        try {
            $storage->delete($path);
        } catch (Throwable) {
        }
    }
}

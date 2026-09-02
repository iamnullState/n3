<?php

declare(strict_types=1);

namespace N3\Module\Media;

use N3\Core\Storage\ScopedModuleStorage;

final readonly class PublicMediaService
{
    public function __construct(private PageMediaRepository $repository, private ScopedModuleStorage $previews)
    {
    }

    public function image(string $publicId): ?MediaPreview
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId) || !$this->repository->isPubliclyAttached($publicId)) {
            return null;
        }
        $contents = $this->previews->read(MediaService::previewPath($publicId));
        if ($contents === null) {
            throw new \RuntimeException('The attached public Media derivative is unavailable.');
        }

        return new MediaPreview($contents, hash('sha256', $contents));
    }
}

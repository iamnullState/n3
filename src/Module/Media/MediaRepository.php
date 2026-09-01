<?php

declare(strict_types=1);

namespace N3\Module\Media;

interface MediaRepository
{
    /** @return list<MediaAsset> */
    public function list(int $limit): array;

    public function find(string $publicId): ?MediaAsset;

    public function create(MediaAsset $asset): void;

    public function allowUpload(string $subject, int $now, int $limit): bool;

    public function recordEvent(string $eventKey, ?string $assetPublicId = null): void;
}

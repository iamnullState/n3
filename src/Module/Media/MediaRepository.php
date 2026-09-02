<?php

declare(strict_types=1);

namespace N3\Module\Media;

interface MediaRepository
{
    /** @return list<MediaAsset> */
    public function list(int $limit): array;

    public function find(string $publicId): ?MediaAsset;

    public function create(MediaAsset $asset): void;

    public function usage(string $publicId): MediaUsage;

    /** @param list<string> $publicIds @return array<string, MediaUsage> */
    public function usages(array $publicIds): array;

    /** Atomically removes an existing asset only when no Page attachment exists. */
    public function deleteIfUnused(string $publicId): bool;

    public function allowUpload(string $subject, int $now, int $limit): bool;

    public function recordEvent(string $eventKey, ?string $assetPublicId = null): void;
}

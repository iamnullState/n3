<?php

declare(strict_types=1);

namespace N3\Module\Media;

use Closure;
use LogicException;

final class LazyMediaRepository implements MediaRepository
{
    private ?MediaRepository $repository = null;
    private Closure $factory;

    public function __construct(Closure $factory)
    {
        $this->factory = $factory;
    }

    public function list(int $limit): array { return $this->repository()->list($limit); }
    public function find(string $publicId): ?MediaAsset { return $this->repository()->find($publicId); }
    public function create(MediaAsset $asset): void { $this->repository()->create($asset); }
    public function allowUpload(string $subject, int $now, int $limit): bool { return $this->repository()->allowUpload($subject, $now, $limit); }
    public function recordEvent(string $eventKey, ?string $assetPublicId = null): void { $this->repository()->recordEvent($eventKey, $assetPublicId); }

    private function repository(): MediaRepository
    {
        if ($this->repository === null) {
            $repository = ($this->factory)();
            if (!$repository instanceof MediaRepository) {
                throw new LogicException('The Media repository factory returned an invalid service.');
            }
            $this->repository = $repository;
        }

        return $this->repository;
    }
}

<?php

declare(strict_types=1);

namespace N3\Module\Media;

final readonly class MediaLifecycleOutcome
{
    public function __construct(public string $status)
    {
        if (!in_array($status, ['deleted', 'regenerated', 'in_use', 'missing'], true)) {
            throw new \InvalidArgumentException('Media lifecycle outcomes are invalid.');
        }
    }
}

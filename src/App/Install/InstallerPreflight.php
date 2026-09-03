<?php

declare(strict_types=1);

namespace N3\App\Install;

final readonly class InstallerPreflight
{
    /** @param array<string, bool> $checks @param array<string, string> $details */
    public function __construct(public array $checks, public array $details)
    {
    }

    public function passes(): bool
    {
        return !in_array(false, $this->checks, true);
    }
}

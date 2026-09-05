<?php

declare(strict_types=1);

namespace N3\App\Install;

interface InstallationStateRepository
{
    public function status(): string;

    public function markComplete(): void;

    public function isComplete(): bool;
}

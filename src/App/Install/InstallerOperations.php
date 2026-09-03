<?php

declare(strict_types=1);

namespace N3\App\Install;

use N3\Core\Http\Request;

interface InstallerOperations
{
    public function status(): string;

    public function preflight(Request $request): InstallerPreflight;

    public function applyMigrations(): void;

    /** @return array<string, string> */
    public function validateAdmin(string $name, string $email, string $password, string $confirmation): array;

    public function createAdmin(string $name, string $email, string $password): void;

    public function adminExists(): bool;

    public function complete(): void;
}

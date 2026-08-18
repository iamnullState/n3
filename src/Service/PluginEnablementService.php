<?php
declare(strict_types=1);

namespace N3\Service;

use N3\Repository\PluginEnablementRepository;

final class PluginEnablementService
{
    public function __construct(private readonly PluginEnablementRepository $overrides) {}

    public function overrides(): array
    {
        return $this->overrides->all();
    }

    public function set(string $pluginId, bool $enabled, int $userId): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $pluginId)) {
            throw new DomainException('Plugin not found.', 404);
        }
        $this->overrides->set($pluginId, $enabled, $userId);
    }
}

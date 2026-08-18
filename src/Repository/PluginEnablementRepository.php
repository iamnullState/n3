<?php
declare(strict_types=1);

namespace N3\Repository;

use PDO;

final class PluginEnablementRepository
{
    public function __construct(private readonly PDO $database) {}

    public function all(): array
    {
        $rows = $this->database->query('SELECT plugin_id, enabled FROM plugin_enablement_overrides ORDER BY plugin_id')->fetchAll();
        $overrides = [];
        foreach ($rows as $row) $overrides[(string)$row['plugin_id']] = (bool)$row['enabled'];
        return $overrides;
    }

    public function set(string $pluginId, bool $enabled, int $userId): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO plugin_enablement_overrides (plugin_id, enabled, updated_by)
            VALUES (?, ?, ?)
            ON CONFLICT(plugin_id) DO UPDATE SET
                enabled = excluded.enabled,
                updated_by = excluded.updated_by,
                updated_at = CURRENT_TIMESTAMP
        SQL);
        $statement->execute([$pluginId, $enabled ? 1 : 0, $userId]);
    }
}

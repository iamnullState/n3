<?php
declare(strict_types=1);

namespace N3\Repository;

use PDO;

final class AppSettingsRepository
{
    public function __construct(private readonly PDO $database) {}

    public function all(): array
    {
        return $this->database->query('SELECT setting_key, setting_value FROM app_settings ORDER BY setting_key')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function setMany(array $settings): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON CONFLICT(setting_key) DO UPDATE SET
                setting_value = excluded.setting_value,
                updated_at = CURRENT_TIMESTAMP
        SQL);
        foreach ($settings as $key => $value) $statement->execute([(string)$key, (string)$value]);
    }
}

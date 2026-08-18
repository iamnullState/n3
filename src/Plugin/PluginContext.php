<?php
declare(strict_types=1);

namespace N3\Plugin;

use N3\Service\PluginMediaService;
use PDO;

final class PluginContext
{
    public function __construct(
        private readonly PDO $database,
        private readonly string $pluginId,
        private readonly string $appUrl,
        private readonly string $appName,
        private readonly ?string $dataDir = null,
    ) {}

    public function database(): PDO
    {
        return $this->database;
    }

    public function pluginId(): string
    {
        return $this->pluginId;
    }

    public function appUrl(): string
    {
        return $this->appUrl;
    }

    public function appName(): string
    {
        return $this->appName;
    }

    public function account(int $userId): ?array
    {
        if ($userId < 1) return null;
        $statement = $this->database->prepare(<<<'SQL'
            SELECT id, username, display_name, profile_slug, avatar_reference, is_admin
            FROM users
            WHERE id = ?
        SQL);
        $statement->execute([$userId]);
        $account = $statement->fetch();
        if (!is_array($account)) return null;
        $slug = trim((string)($account['profile_slug'] ?? ''));
        $displayName = trim((string)($account['display_name'] ?? '')) ?: (string)$account['username'];
        return [
            'id' => (int)$account['id'],
            'display_name' => mb_substr($displayName, 0, 120),
            'profile_url' => $slug === '' ? null : '/u/' . rawurlencode($slug),
            'avatar_url' => $slug !== '' && trim((string)($account['avatar_reference'] ?? '')) !== ''
                ? '/avatar/' . rawurlencode($slug)
                : null,
            'is_admin' => (bool)$account['is_admin'],
        ];
    }

    public function storeMedia(array $upload): array
    {
        if ($this->dataDir === null) throw new \RuntimeException('Plugin media storage is unavailable.');
        return (new PluginMediaService($this->dataDir))->store($this->pluginId, $upload);
    }

    public function removeMedia(string $filename): bool
    {
        if ($this->dataDir === null) return false;
        return (new PluginMediaService($this->dataDir))->remove($this->pluginId, $filename);
    }
}

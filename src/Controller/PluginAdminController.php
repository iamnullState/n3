<?php
declare(strict_types=1);

namespace N3\Controller;

use N3\Http\Request;
use N3\Http\Response;
use N3\Plugin\PluginManager;
use N3\Service\DomainException;
use N3\Service\PluginEnablementService;
use N3\Service\PluginArchiveInstaller;
use PDO;

final class PluginAdminController
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PluginEnablementService $enablement,
        private readonly ?PluginArchiveInstaller $installer = null,
        private readonly ?PDO $database = null,
    ) {}

    public function index(array $user): never
    {
        $this->assertAdministrator($user);
        $this->plugins->boot($this->database);
        Response::json(['plugins' => $this->plugins->inventory()])->send();
    }

    public function update(string $pluginId, Request $request, array $user): never
    {
        $this->assertAdministrator($user);
        $plugin = $this->find($pluginId);
        if ($plugin === null) Response::json(['error' => 'Plugin not found.'], 404)->send();
        if ($plugin['status'] === 'invalid') {
            Response::json(['error' => 'Fix the plugin manifest before changing its enablement.'], 409)->send();
        }

        $data = $request->json();
        if ($data === null) Response::json(['error' => 'Invalid JSON body.'], 400)->send();
        if (!array_key_exists('enabled', $data) || !is_bool($data['enabled'])) {
            Response::json(['error' => 'The enabled field must be boolean.'], 422)->send();
        }

        $enabled = $data['enabled'];
        try {
            $this->enablement->set($pluginId, $enabled, (int)$user['id']);
        } catch (DomainException $error) {
            Response::json(['error' => $error->getMessage()], $error->status())->send();
        }
        $plugin['override_enabled'] = $enabled;
        $plugin['effective_enabled'] = $enabled;
        $plugin['status'] = $enabled ? 'enabled' : 'disabled';
        $plugin['diagnostic'] = null;
        Response::json(['plugin' => $plugin, 'reload_required' => true])->send();
    }

    public function upload(array $upload, array $user): never
    {
        $this->assertAdministrator($user);
        if ($this->installer === null) Response::json(['error' => 'Plugin uploads are unavailable.'], 503)->send();
        try {
            $pluginId = $this->installer->install($upload);
            Response::json(['plugin_id' => $pluginId, 'reload_required' => true], 201)->send();
        } catch (DomainException $error) {
            Response::json(['error' => $error->getMessage()], $error->status())->send();
        }
    }

    private function find(string $pluginId): ?array
    {
        foreach ($this->plugins->inventory() as $plugin) {
            if (hash_equals($plugin['id'], $pluginId)) return $plugin;
        }
        return null;
    }

    private function assertAdministrator(array $user): void
    {
        if (!(bool)($user['is_admin'] ?? false)) {
            Response::json(['error' => 'Administrator access is required.'], 403)->send();
        }
    }
}

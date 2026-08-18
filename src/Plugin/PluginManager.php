<?php
declare(strict_types=1);

namespace N3\Plugin;

use N3\Config;
use N3\Http\Request;
use N3\Http\Response;
use JsonException;
use N3\Service\PluginMediaService;
use PDO;
use stdClass;
use Throwable;

final class PluginManager
{
    private const CONTRIBUTION_SLOTS = ['profile_tools', 'profile_cards', 'page_information'];
    private const ASSET_MIME = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
    ];

    private bool $discovered = false;
    private bool $booted = false;
    private array $definitions = [];

    public function __construct(private readonly string $directory, private readonly PluginRegistry $registry) {}

    public function discover(): array
    {
        if ($this->discovered) return $this->inventory();
        $this->discovered = true;
        if (!is_dir($this->directory)) return [];

        $manifests = glob(rtrim($this->directory, '/') . '/*/plugin.json') ?: [];
        sort($manifests, SORT_STRING);
        foreach ($manifests as $manifestPath) {
            $this->definitions[] = $this->definition($manifestPath);
        }
        $this->validatePublicPrefixOwnership();
        return $this->inventory();
    }

    public function inventory(): array
    {
        if (!$this->discovered) return $this->discover();
        return array_map(static fn(array $definition): array => [
            'id' => $definition['id'],
            'name' => $definition['name'],
            'version' => $definition['version'],
            'enabled' => $definition['manifest_enabled'],
            'manifest_enabled' => $definition['manifest_enabled'],
            'override_enabled' => $definition['override_enabled'],
            'effective_enabled' => $definition['effective_enabled'],
            'status' => $definition['status'],
            'diagnostic' => $definition['diagnostic'],
            'capabilities' => $definition['capabilities'],
        ], $this->definitions);
    }

    public function applyEnablementOverrides(array $overrides): void
    {
        if ($this->booted) throw new \LogicException('Plugin enablement overrides must be applied before boot.');
        $this->discover();
        foreach ($this->definitions as $index => $definition) {
            $override = array_key_exists($definition['id'], $overrides) && is_bool($overrides[$definition['id']])
                ? $overrides[$definition['id']]
                : null;
            $effective = $override ?? $definition['manifest_enabled'];
            $this->definitions[$index]['override_enabled'] = $override;
            $this->definitions[$index]['effective_enabled'] = $effective;
            if ($definition['status'] !== 'invalid') {
                $this->definitions[$index]['status'] = $effective ? 'enabled' : 'disabled';
            }
        }
    }

    public function boot(?PDO $database = null): PluginRegistry
    {
        if ($this->booted) return $this->registry;
        $this->discover();
        $this->booted = true;

        foreach ($this->definitions as $index => $definition) {
            if ($definition['status'] !== 'enabled') continue;
            $registration = null;
            try {
                if ($definition['migrations'] !== []) {
                    if ($database === null) throw new \RuntimeException('Plugin database context is unavailable.');
                    $this->migratePlugin($database, $definition);
                }
                $registration = $this->registry->begin($definition['plugin']);
                foreach ($definition['manifest']['dashboard'] ?? [] as $widget) {
                    $this->registry->dashboardWidget($widget);
                }
                foreach (array_slice($definition['manifest']['navigation'] ?? [], 0, 5) as $item) {
                    $this->registry->navigationItem($item);
                }
                $bootstrap = dirname($definition['manifest_path']) . '/bootstrap.php';
                if (is_file($bootstrap)) {
                    $loader = require $bootstrap;
                    if (is_callable($loader)) {
                        if ($database === null) $loader($this->registry);
                        else $loader($this->registry, $this->context($database, $definition['id']));
                    }
                }
                $this->registry->commit($registration);
                $this->definitions[$index]['status'] = 'loaded';
            } catch (Throwable $error) {
                if ($registration !== null) $this->registry->discard($registration);
                $this->definitions[$index]['status'] = 'failed';
                $this->definitions[$index]['diagnostic'] = $error instanceof PluginRegistrationException
                    ? $error->getMessage()
                    : 'Plugin bootstrap failed. Check the application log.';
                error_log('Plugin ' . $definition['id'] . ' failed during bootstrap: ' . $error->getMessage());
            }
        }
        return $this->registry;
    }

    public function publicResponse(Request $request, PDO $database): ?Response
    {
        $this->discover();
        $definition = $this->publicDefinitionForPath($request->path());
        if ($definition === null) return null;
        $notFound = new Response(
            $request->method() === 'HEAD' ? '' : 'Link not found.',
            404,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
        if (!$definition['effective_enabled'] || $definition['status'] === 'invalid') return $notFound;
        if (!$this->migrationsReady($database, $definition)) return $notFound;

        $registry = new PublicPluginRegistry();
        $registration = null;
        try {
            $registration = $registry->begin($definition['id'], $definition['public']['prefixes']);
            $loader = require $definition['public']['bootstrap_path'];
            if (!is_callable($loader)) throw new \RuntimeException('Public plugin bootstrap is not callable.');
            $loader($registry, $this->context($database, $definition['id']));
            $registry->commit($registration);
            return $registry->dispatch($request) ?? $notFound;
        } catch (Throwable) {
            if ($registration !== null) {
                try { $registry->discard($registration); } catch (Throwable) {}
            }
            error_log('Plugin ' . $definition['id'] . ' failed during public request handling.');
            return $notFound;
        }
    }

    public function claimsPublicPath(string $path): bool
    {
        $this->discover();
        return $this->publicDefinitionForPath($path) !== null;
    }

    public function asset(string $pluginId, string $filename): ?array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $pluginId) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/D', $filename)) return null;
        $this->discover();
        foreach ($this->definitions as $definition) {
            if ($definition['id'] !== $pluginId || $definition['status'] !== 'loaded') continue;
            $mime = $definition['assets'][$filename] ?? null;
            return is_string($mime) ? $this->resolveAsset($pluginId, $filename, $mime) : null;
        }
        return null;
    }

    public function media(string $pluginId, string $filename): ?array
    {
        $this->discover();
        foreach ($this->definitions as $definition) {
            if ($definition['id'] !== $pluginId || $definition['status'] !== 'loaded') continue;
            return (new PluginMediaService(Config::dataDir()))->find($pluginId, $filename);
        }
        return null;
    }

    private function browserAssets(string $pluginId, array $manifest): array
    {
        $result = ['css' => [], 'js' => [], 'allowlist' => []];
        foreach (self::ASSET_MIME as $type => $mime) {
            foreach (array_slice($manifest[$type] ?? [], 0, 20) as $value) {
                $filename = trim($value);
                if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\.' . $type . '$/D', $filename)) continue;
                if (array_key_exists($filename, $result['allowlist'])) continue;
                if ($this->resolveAsset($pluginId, $filename, $mime) === null) continue;
                $result[$type][] = '/plugin-assets/' . rawurlencode($pluginId) . '/' . rawurlencode($filename);
                $result['allowlist'][$filename] = $mime;
            }
        }
        return $result;
    }

    private function resolveAsset(string $pluginId, string $filename, string $mime): ?array
    {
        $root = realpath($this->directory . '/' . $pluginId);
        $path = realpath($this->directory . '/' . $pluginId . '/' . $filename);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) return null;
        $expectedMime = self::ASSET_MIME[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        return $expectedMime === $mime ? ['path' => $path, 'mime' => $mime] : null;
    }

    private function definition(string $manifestPath): array
    {
        $id = basename(dirname($manifestPath));
        $default = [
            'id' => mb_substr($id, 0, 80),
            'name' => mb_substr($id, 0, 80),
            'version' => '0.0.0',
            'manifest_enabled' => false,
            'override_enabled' => null,
            'effective_enabled' => false,
            'status' => 'invalid',
            'diagnostic' => null,
            'manifest' => [],
            'manifest_path' => $manifestPath,
            'plugin' => null,
            'public' => null,
            'migrations' => [],
            'assets' => [],
            'capabilities' => [
                'php_bootstrap' => false,
                'public_routes' => false,
                'migrations' => 0,
                'dashboard_widgets' => 0,
                'navigation_items' => 0,
                'css_assets' => 0,
                'js_assets' => 0,
                'profile_tools' => false,
                'profile_cards' => false,
                'page_information' => false,
            ],
        ];
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $id)) {
            return array_replace($default, ['diagnostic' => 'Plugin directory ID is invalid.']);
        }

        $contents = @file_get_contents($manifestPath);
        if ($contents === false) return array_replace($default, ['diagnostic' => 'Plugin manifest could not be read.']);
        try {
            $root = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return array_replace($default, ['diagnostic' => 'Plugin manifest is not valid JSON.']);
        }
        if (!$root instanceof stdClass || !is_array($manifest)) {
            return array_replace($default, ['diagnostic' => 'Plugin manifest must contain a JSON object.']);
        }

        $diagnostic = $this->validateManifest($root);
        if ($diagnostic !== null) return array_replace($default, ['diagnostic' => $diagnostic]);

        [$public, $publicDiagnostic] = $this->publicDefinition($manifest, dirname($manifestPath));
        if ($publicDiagnostic !== null) return array_replace($default, ['diagnostic' => $publicDiagnostic, 'public' => $public]);
        [$migrations, $migrationDiagnostic] = $this->migrationDefinitions($manifest, dirname($manifestPath));
        if ($migrationDiagnostic !== null) return array_replace($default, ['diagnostic' => $migrationDiagnostic, 'public' => $public]);

        $enabled = $manifest['enabled'] ?? true;
        $name = mb_substr(trim($manifest['name'] ?? $id), 0, 80);
        $version = mb_substr(trim($manifest['version'] ?? '0.0.0'), 0, 30);
        $assets = $this->browserAssets($id, $manifest);
        $contributionSlots = array_values(array_unique($manifest['contributions'] ?? []));
        return array_replace($default, [
            'id' => $id,
            'name' => $name,
            'version' => $version,
            'manifest_enabled' => $enabled,
            'effective_enabled' => $enabled,
            'status' => $enabled ? 'enabled' : 'disabled',
            'manifest' => $manifest,
            'public' => $public,
            'migrations' => $migrations,
            'assets' => $assets['allowlist'],
            'capabilities' => [
                'php_bootstrap' => is_file(dirname($manifestPath) . '/bootstrap.php'),
                'public_routes' => $public !== null,
                'migrations' => count($migrations),
                'dashboard_widgets' => count($manifest['dashboard'] ?? []),
                'navigation_items' => count($manifest['navigation'] ?? []),
                'css_assets' => count($assets['css']),
                'js_assets' => count($assets['js']),
                'profile_tools' => in_array('profile_tools', $contributionSlots, true),
                'profile_cards' => in_array('profile_cards', $contributionSlots, true),
                'page_information' => in_array('page_information', $contributionSlots, true),
            ],
            'plugin' => [
                'id' => $id,
                'name' => $name,
                'version' => $version,
                'css' => $assets['css'],
                'js' => $assets['js'],
                'dashboard' => [],
                'navigation' => [],
                'contribution_slots' => $contributionSlots,
            ],
        ]);
    }

    private function validateManifest(stdClass $manifest): ?string
    {
        $fields = get_object_vars($manifest);
        foreach (['name', 'version'] as $field) {
            if (array_key_exists($field, $fields) && !is_string($fields[$field])) {
                return 'Plugin manifest field "' . $field . '" must be a string.';
            }
        }
        if (array_key_exists('enabled', $fields) && !is_bool($fields['enabled'])) {
            return 'Plugin manifest field "enabled" must be boolean.';
        }
        foreach (['css', 'js'] as $field) {
            if (!array_key_exists($field, $fields)) continue;
            if (!is_array($fields[$field])) return 'Plugin manifest field "' . $field . '" must be an array.';
            foreach ($fields[$field] as $value) {
                if (!is_string($value)) return 'Plugin manifest field "' . $field . '" must contain only strings.';
            }
        }
        if (array_key_exists('migrations', $fields)) {
            if (!is_array($fields['migrations'])) return 'Plugin manifest field "migrations" must be an array.';
            foreach ($fields['migrations'] as $value) {
                if (!is_string($value)) return 'Plugin manifest field "migrations" must contain only strings.';
            }
        }
        if (array_key_exists('public', $fields)) {
            if (!$fields['public'] instanceof stdClass) return 'Plugin manifest field "public" must be an object.';
            $public = get_object_vars($fields['public']);
            if (($public['bootstrap'] ?? null) !== 'public.php') return 'Plugin public bootstrap must be "public.php".';
            if (!isset($public['prefixes']) || !is_array($public['prefixes']) || $public['prefixes'] === [] || count($public['prefixes']) > 4) {
                return 'Plugin public prefixes must be a non-empty array of at most four values.';
            }
            if (count(array_unique($public['prefixes'])) !== count($public['prefixes'])) return 'Plugin public prefixes must be unique.';
            foreach ($public['prefixes'] as $prefix) {
                if (!is_string($prefix) || !preg_match('~^/[a-z0-9][a-z0-9_-]{0,31}$~D', $prefix)) {
                    return 'Plugin public prefix is invalid.';
                }
                if (in_array($prefix, self::reservedPublicPrefixes(), true)) return 'Plugin public prefix is reserved by core.';
            }
        }
        if (array_key_exists('contributions', $fields)) {
            if (!is_array($fields['contributions'])) return 'Plugin manifest field "contributions" must be an array.';
            foreach ($fields['contributions'] as $value) {
                if (!is_string($value) || !in_array($value, self::CONTRIBUTION_SLOTS, true)) {
                    return 'Plugin manifest field "contributions" contains an unsupported slot.';
                }
            }
        }
        if (array_key_exists('navigation', $fields)) {
            if (!is_array($fields['navigation']) || count($fields['navigation']) > 5) return 'Plugin manifest field "navigation" must be an array of at most five entries.';
            foreach ($fields['navigation'] as $item) {
                if (!$item instanceof stdClass) return 'Plugin navigation entries must be objects.';
                $itemFields = get_object_vars($item);
                foreach (['label', 'url', 'icon'] as $field) {
                    if (array_key_exists($field, $itemFields) && !is_string($itemFields[$field])) {
                        return 'Plugin navigation field "' . $field . '" must be a string.';
                    }
                }
            }
        }
        if (!array_key_exists('dashboard', $fields)) return null;
        if (!is_array($fields['dashboard'])) return 'Plugin manifest field "dashboard" must be an array.';
        foreach ($fields['dashboard'] as $widget) {
            if (!$widget instanceof stdClass) return 'Plugin dashboard entries must be objects.';
            $widgetFields = get_object_vars($widget);
            foreach (['title', 'body', 'url'] as $field) {
                if (array_key_exists($field, $widgetFields) && !is_string($widgetFields[$field])) {
                    return 'Plugin dashboard field "' . $field . '" must be a string.';
                }
            }
        }
        return null;
    }

    private function publicDefinition(array $manifest, string $directory): array
    {
        if (!isset($manifest['public'])) return [null, null];
        $path = $directory . '/public.php';
        $prefixes = array_values(array_unique($manifest['public']['prefixes']));
        $public = ['bootstrap_path' => $path, 'prefixes' => $prefixes];
        return is_file($path) ? [$public, null] : [$public, 'Plugin public bootstrap file is missing.'];
    }

    private function migrationDefinitions(array $manifest, string $directory): array
    {
        $values = $manifest['migrations'] ?? [];
        $migrations = [];
        $expected = 1;
        foreach ($values as $value) {
            if (!preg_match('~^migrations/(\d{3})_[a-z0-9_]+\.php$~D', $value, $match) || (int)$match[1] !== $expected) {
                return [[], 'Plugin migrations must be sequentially numbered safe PHP paths.'];
            }
            $path = $directory . '/' . $value;
            if (!is_file($path)) return [[], 'A declared plugin migration file is missing.'];
            $checksum = hash_file('sha256', $path);
            if (!is_string($checksum)) return [[], 'A declared plugin migration could not be read.'];
            $migrations[] = ['version' => $expected, 'path' => $path, 'checksum' => $checksum];
            $expected++;
        }
        return [$migrations, null];
    }

    private function migratePlugin(PDO $database, array $definition): void
    {
        $this->assertMigrationLedger($database);
        $applied = $this->appliedMigrations($database, $definition['id']);
        foreach ($applied as $version => $record) {
            $declared = $definition['migrations'][$version - 1] ?? null;
            if ($declared === null) throw new \RuntimeException('Plugin data was created by a newer plugin version.');
            if (!hash_equals($record['checksum'], $declared['checksum'])) throw new \RuntimeException('Applied plugin migration checksum does not match.');
        }
        $pending = array_values(array_filter(
            $definition['migrations'],
            static fn(array $migration): bool => !isset($applied[$migration['version']]),
        ));
        if ($pending === []) return;

        $database->exec('BEGIN IMMEDIATE');
        try {
            $insert = $database->prepare('INSERT INTO plugin_migrations (plugin_id, migration, name, checksum) VALUES (?, ?, ?, ?)');
            foreach ($pending as $migration) {
                $loaded = require $migration['path'];
                if (!is_array($loaded) || !is_string($loaded['name'] ?? null) || !is_callable($loaded['up'] ?? null)) {
                    throw new \RuntimeException('Plugin migration definition is invalid.');
                }
                ($loaded['up'])($database);
                $insert->execute([$definition['id'], $migration['version'], mb_substr($loaded['name'], 0, 120), $migration['checksum']]);
            }
            if ($database->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
                throw new \RuntimeException('A plugin migration introduced a foreign-key violation.');
            }
            $database->exec('COMMIT');
        } catch (Throwable $error) {
            try { $database->exec('ROLLBACK'); } catch (Throwable) {}
            throw $error;
        }
    }

    private function migrationsReady(PDO $database, array $definition): bool
    {
        if ($definition['migrations'] === []) return true;
        try {
            $this->assertMigrationLedger($database);
            $applied = $this->appliedMigrations($database, $definition['id']);
            if (count($applied) !== count($definition['migrations'])) return false;
            foreach ($definition['migrations'] as $migration) {
                $record = $applied[$migration['version']] ?? null;
                if ($record === null || !hash_equals($record['checksum'], $migration['checksum'])) return false;
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function assertMigrationLedger(PDO $database): void
    {
        $exists = (bool)$database->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'plugin_migrations'")->fetchColumn();
        if (!$exists) throw new \RuntimeException('Plugin migration ledger is unavailable.');
    }

    private function appliedMigrations(PDO $database, string $pluginId): array
    {
        $statement = $database->prepare('SELECT migration, checksum FROM plugin_migrations WHERE plugin_id = ? ORDER BY migration');
        $statement->execute([$pluginId]);
        $records = [];
        foreach ($statement->fetchAll() as $record) $records[(int)$record['migration']] = ['checksum' => (string)$record['checksum']];
        return $records;
    }

    private function context(PDO $database, string $pluginId): PluginContext
    {
        return new PluginContext($database, $pluginId, Config::appUrl(), Config::appName(), Config::dataDir());
    }

    private function publicDefinitionForPath(string $path): ?array
    {
        foreach ($this->definitions as $definition) {
            foreach ($definition['public']['prefixes'] ?? [] as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) return $definition;
            }
        }
        return null;
    }

    private function validatePublicPrefixOwnership(): void
    {
        $owners = [];
        foreach ($this->definitions as $index => $definition) {
            if ($definition['status'] === 'invalid') continue;
            foreach ($definition['public']['prefixes'] ?? [] as $prefix) {
                if (!isset($owners[$prefix])) {
                    $owners[$prefix] = $index;
                    continue;
                }
                foreach ([$owners[$prefix], $index] as $conflict) {
                    $this->definitions[$conflict]['status'] = 'invalid';
                    $this->definitions[$conflict]['effective_enabled'] = false;
                    $this->definitions[$conflict]['diagnostic'] = 'Plugin public prefix is already claimed.';
                }
            }
        }
    }

    private static function reservedPublicPrefixes(): array
    {
        return ['/api', '/avatar', '/brand', '/dashboard', '/feed.xml', '/login', '/logout', '/media', '/p', '/page', '/plugin-assets', '/plugin-media', '/preview', '/public', '/setup', '/sitemap.xml', '/tags', '/u'];
    }
}

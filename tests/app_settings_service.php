<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\AppSettingsRepository;
use N3\Service\AppSettingsService;
use N3\Service\DomainException;

function verifyAppSettings(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$directory = sys_get_temp_dir() . '/n3-app-settings-' . bin2hex(random_bytes(5));
mkdir($directory, 0700, true);

try {
    $service = new AppSettingsService(new AppSettingsRepository($database), $directory);
    $defaults = $service->all();
    verifyAppSettings($defaults['brandName'] !== '' && $defaults['themes']['light']['text'] === '#242424' && $defaults['themes']['dark']['text'] === '#f8f3f4', 'settings expose complete safe light and dark defaults');

    $light = $defaults['themes']['light'];
    $dark = $defaults['themes']['dark'];
    $light['text'] = '#112233';
    $dark['accent'] = '#abcdef';
    $updated = $service->update([
        'brandName' => 'Field Notes', 'tailscaleIp' => '100.64.0.8', 'port' => 9443,
        'appUrl' => 'https://notes.example.test', 'themes' => ['light' => $light, 'dark' => $dark],
    ]);
    verifyAppSettings($updated['brandName'] === 'Field Notes' && $updated['port'] === 9443 && $updated['themes']['light']['text'] === '#112233' && $updated['themes']['dark']['accent'] === '#abcdef', 'brand, address, and both theme palettes persist together');
    verifyAppSettings((new AppSettingsService(new AppSettingsRepository($database), $directory))->all() === $updated, 'settings survive a new service instance');

    foreach ([
        ['brandName' => ''], ['tailscaleIp' => 'not-an-ip'], ['port' => 70000],
        ['appUrl' => 'javascript:alert(1)'], ['themes' => ['light' => ['text' => 'red']]],
    ] as $invalid) {
        try {
            $service->update($invalid);
            throw new RuntimeException('Invalid settings were accepted.');
        } catch (DomainException $error) {
            verifyAppSettings($error->status() === 422, 'invalid setting input is rejected with a validation response');
        }
    }
} finally {
    if (is_dir($directory)) rmdir($directory);
}

echo "\nn3 application settings service test passed.\n";

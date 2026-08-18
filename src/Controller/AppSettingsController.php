<?php
declare(strict_types=1);

namespace N3\Controller;

use N3\Config;
use N3\Http\Request;
use N3\Http\Response;
use N3\Service\AppSettingsService;
use N3\Service\DomainException;

final class AppSettingsController
{
    public function __construct(private readonly AppSettingsService $settings) {}

    public function show(array $user): never
    {
        $this->assertAdministrator($user);
        Response::json(['settings' => $this->settings->all()])->send();
    }

    public function update(Request $request, array $user): never
    {
        $this->assertAdministrator($user);
        $input = $request->json();
        if ($input === null) Response::json(['error' => 'Invalid JSON body.'], 400)->send();
        try {
            $settings = $this->settings->update($input);
            Config::setRuntimeSettings($settings);
            Response::json(['settings' => $settings])->send();
        } catch (DomainException $error) {
            Response::json(['error' => $error->getMessage()], $error->status())->send();
        }
    }

    public function upload(string $kind, array $upload, array $user): never
    {
        $this->assertAdministrator($user);
        try {
            Response::json(['settings' => $this->settings->storeBrandAsset($kind, $upload)])->send();
        } catch (DomainException $error) {
            Response::json(['error' => $error->getMessage()], $error->status())->send();
        }
    }

    private function assertAdministrator(array $user): void
    {
        if (!(bool)($user['is_admin'] ?? false)) Response::json(['error' => 'Administrator access is required.'], 403)->send();
    }
}

<?php
declare(strict_types=1);

namespace N3\Controller;

use N3\Http\Response;
use N3\Service\SystemDiagnosticsService;

final class SystemDiagnosticsController
{
    public function __construct(private readonly SystemDiagnosticsService $diagnostics) {}

    public function index(array $user): never
    {
        if (!(bool)($user['is_admin'] ?? false)) {
            Response::json(['error' => 'Administrator access is required.'], 403)->send();
        }
        Response::json(['diagnostics' => $this->diagnostics->report()])->send();
    }
}

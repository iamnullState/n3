<?php

declare(strict_types=1);

namespace N3\Core\Observability;

use N3\Core\Http\Request;

final class RequestMetricClassifier
{
    public function classify(Request $request): string
    {
        $path = $request->path;

        if ($path === '/') {
            return 'public.home';
        }

        if (preg_match('#^/pages/[^/]+$#D', $path) === 1) {
            return 'public.page';
        }

        if ($path === '/register'
            || $path === '/login'
            || $path === '/logout'
            || $path === '/account'
            || $path === '/forgot-password'
            || $path === '/reset-password'
            || $path === '/verify-email'
            || $path === '/verify-email/resend') {
            return 'identity';
        }

        if ($path === '/admin/pages' || str_starts_with($path, '/admin/pages/')) {
            return 'admin.pages';
        }

        if ($path === '/admin/analytics') {
            return 'admin.analytics';
        }

        if ($path === '/api/v1/system/ping') {
            return 'api.system';
        }

        if ($path === '/api' || str_starts_with($path, '/api/')) {
            return 'api.other';
        }

        return 'other';
    }
}

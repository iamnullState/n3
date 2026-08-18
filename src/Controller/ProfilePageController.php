<?php
declare(strict_types=1);

namespace N3\Controller;

use N3\Config;
use N3\Http\Response;
use N3\Service\ProfileService;
use N3\View\ViewRenderer;

final class ProfilePageController
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly ViewRenderer $views,
    ) {}

    public function show(string $slug): never
    {
        $profile = $this->profiles->viewBySlug($slug);
        if ($profile === null) $this->notFound();
        $public = $profile['audience'] === 'public';
        $canonical = Config::appUrl() . '/u/' . rawurlencode((string)$profile['profile_slug']);
        $body = $this->views->render('profile/show', [
            'appName' => Config::appName(),
            'canonical' => $canonical,
            'profile' => $profile,
        ]);
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => $public ? 'no-cache' : 'no-store',
        ];
        if (!$public) $headers['X-Robots-Tag'] = 'noindex, nofollow';
        (new Response($body, 200, $headers))->send();
    }

    public function notFound(): never
    {
        (new Response('Profile not found.', 404, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]))->send();
    }
}

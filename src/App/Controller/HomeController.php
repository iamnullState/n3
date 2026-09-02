<?php

declare(strict_types=1);

namespace N3\App\Controller;

use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\View\View;

final readonly class HomeController
{
    /**
     * @param array{name: string, version: string, environment: string, debug: bool, timezone: string} $config
     */
    public function __construct(
        private View $view,
        private array $config,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        return Response::html($this->view->render('home/index', [
            'pageTitle' => 'Hello, world',
            'metaDescription' => 'Hello, world.',
        ]));
    }
}

<?php

declare(strict_types=1);

namespace N3\Core;

use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Http\RouteNotFound;
use N3\Core\Http\Router;
use N3\Core\Logging\FileLogger;
use N3\Core\View\View;
use Throwable;

final readonly class Application
{
    public function __construct(
        private Router $router,
        private View $view,
        private FileLogger $logger,
        private string $environment,
    ) {
    }

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(8));

        try {
            $response = $this->router->dispatch($request);
        } catch (RouteNotFound) {
            $response = $this->errorResponse('errors/404', 'Page not found', 404);
        } catch (Throwable $exception) {
            $this->logger->error('unhandled_exception', [
                'request_id' => $requestId,
                'exception' => $exception::class,
                'environment' => $this->environment,
            ]);

            $response = $this->errorResponse('errors/500', 'Something went wrong', 500);
        }

        return $this->secure($response, $requestId);
    }

    private function errorResponse(string $view, string $title, int $status): Response
    {
        try {
            return Response::html(
                $this->view->render($view, [
                    'pageTitle' => $title,
                    'metaDescription' => $title . ' — N3',
                    'environment' => $this->environment,
                ]),
                $status,
            );
        } catch (Throwable) {
            return Response::html(
                '<!doctype html><html lang="en"><meta charset="utf-8"><title>'
                . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</title><h1>'
                . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</h1></html>',
                $status,
            );
        }
    }

    private function secure(Response $response, string $requestId): Response
    {
        return $response
            ->withHeader('X-Request-ID', $requestId)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; "
                . "object-src 'none'; img-src 'self' data:; style-src 'self'; script-src 'self'",
            );
    }
}

<?php

declare(strict_types=1);

namespace N3\Core;

use DateTimeImmutable;
use DateTimeZone;
use N3\Core\Api\ApiRequestRejected;
use N3\Core\Api\ApiResponder;
use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Http\MethodNotAllowed;
use N3\Core\Http\RouteNotFound;
use N3\Core\Http\Router;
use N3\Core\Logging\FileLogger;
use N3\Core\Observability\RequestMetric;
use N3\Core\Observability\RequestMetricClassifier;
use N3\Core\Observability\RequestMetricsSink;
use N3\Core\View\View;
use Throwable;

final readonly class Application
{
    public function __construct(
        private Router $router,
        private View $view,
        private FileLogger $logger,
        private string $environment,
        private ?RequestMetricsSink $requestMetrics = null,
        private RequestMetricClassifier $requestMetricClassifier = new RequestMetricClassifier(),
    ) {
    }

    public function handle(Request $request): Response
    {
        $startedAt = hrtime(true);
        $requestId = bin2hex(random_bytes(8));
        $request = $request->withAttribute('request_id', $requestId);

        try {
            $response = $this->router->dispatch($request);
        } catch (RouteNotFound) {
            $response = $this->isApi($request)
                ? ApiResponder::error('not_found', 'Resource not found.', $requestId, 404)
                : $this->errorResponse('errors/404', 'Page not found', 404);
        } catch (MethodNotAllowed $exception) {
            $response = ($this->isApi($request)
                ? ApiResponder::error('method_not_allowed', 'Method not allowed.', $requestId, 405)
                : $this->errorResponse('errors/404', 'Method not allowed', 405))
                ->withHeader('Allow', implode(', ', $exception->allowed));
        } catch (ApiRequestRejected $exception) {
            $response = $this->isApi($request)
                ? ApiResponder::error(
                    $exception->errorCode,
                    $exception->getMessage(),
                    $requestId,
                    $exception->status,
                )
                : $this->errorResponse('errors/500', 'Something went wrong', 500);
        } catch (Throwable $exception) {
            $this->logger->error('unhandled_exception', [
                'request_id' => $requestId,
                'exception' => $exception::class,
                'environment' => $this->environment,
            ]);

            $response = $this->isApi($request)
                ? ApiResponder::error('internal_error', 'The request could not be completed.', $requestId, 500)
                : $this->errorResponse('errors/500', 'Something went wrong', 500);
        }

        $response = $this->secure($response, $requestId);
        $this->recordRequestMetric($request, $response, $startedAt);

        return $response;
    }

    private function recordRequestMetric(Request $request, Response $response, int $startedAt): void
    {
        if ($this->requestMetrics === null) {
            return;
        }

        try {
            $duration = max(0, intdiv(hrtime(true) - $startedAt, 1_000));
            $this->requestMetrics->record(new RequestMetric(
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                $this->requestMetricClassifier->classify($request),
                $request->method,
                $response->status(),
                min($duration, 60_000_000),
            ));
        } catch (Throwable $exception) {
            $this->logger->error('request_metrics_failed', [
                'exception' => $exception::class,
                'environment' => $this->environment,
            ]);
        }
    }

    private function isApi(Request $request): bool
    {
        return $request->path === '/api' || str_starts_with($request->path, '/api/');
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

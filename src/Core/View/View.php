<?php

declare(strict_types=1);

namespace N3\Core\View;

use Throwable;

final readonly class View
{
    public function __construct(private string $basePath)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $templateFile = $this->resolve($template);
        $viewData = $data;
        $escape = static fn (mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        ob_start();

        try {
            require $templateFile;
            $content = (string) ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        if ($layout === null) {
            return $content;
        }

        $layoutFile = $this->resolve($layout);
        ob_start();

        try {
            require $layoutFile;

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }

    private function resolve(string $template): string
    {
        if ($template === '' || str_contains($template, '..')) {
            throw new ViewException('Invalid view name.');
        }

        $file = rtrim($this->basePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . ltrim($template, DIRECTORY_SEPARATOR)
            . '.php';

        if (!is_file($file)) {
            throw new ViewException(sprintf('View "%s" was not found.', $template));
        }

        return $file;
    }
}

<?php
declare(strict_types=1);

namespace N3\View;

use RuntimeException;

final class ViewRenderer
{
    public function __construct(private readonly string $directory) {}

    public function render(string $view, array $data = []): string
    {
        $file = $this->directory . '/' . $view . '.php';
        if (!is_file($file)) throw new RuntimeException("View not found: $view");
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string)ob_get_clean();
    }
}

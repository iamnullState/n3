<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'N3\\';
    if (!str_starts_with($class, $prefix)) return;

    $relative = substr($class, strlen($prefix));
    if ($relative === '' || str_contains($relative, '..')) return;

    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require $file;
});


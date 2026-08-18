<?php
declare(strict_types=1);

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="en" data-theme="system"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title><?= $escape($documentTitle) ?></title><?= $head ?? '' ?><link rel="stylesheet" href="/assets/style.css"><script src="/assets/public.js" defer></script></head><body class="public-site"><header class="public-header"><?= $header ?><button class="public-theme-toggle" type="button" aria-label="Change color theme" title="Theme: System"><span aria-hidden="true">◐</span><span class="public-theme-label">System</span></button></header><?= $body ?></body></html>

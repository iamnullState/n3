<?php

declare(strict_types=1);

/** @var array<string, mixed> $viewData */
/** @var Closure(mixed): string $escape */
/** @var string $content */
?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="<?= $escape($viewData['metaDescription']) ?>">
        <meta name="robots" content="noindex, nofollow">
        <meta name="color-scheme" content="light dark">
        <title><?= $escape($viewData['pageTitle']) ?></title>
        <link rel="icon" href="data:,">
        <link rel="stylesheet" href="/assets/css/app.css">
        <script src="/assets/javascript/identity.js" defer></script>
    </head>
    <body>
        <a class="skip-link" href="#main-content">Skip to setup</a>
        <?= $content ?>
    </body>
</html>

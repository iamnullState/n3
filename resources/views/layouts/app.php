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
        <meta name="description" content="<?= $escape($viewData['metaDescription'] ?? 'N3 CMS') ?>">
        <?php if (isset($viewData['robots'])): ?><meta name="robots" content="<?= $escape($viewData['robots']) ?>"><?php endif; ?>
        <meta name="color-scheme" content="light dark">
        <title><?= $escape($viewData['pageTitle'] ?? 'N3') ?></title>
        <link rel="icon" href="data:,">
        <link rel="stylesheet" href="/assets/css/app.css">
        <?php if (isset($viewData['siteIdentity'])): ?><link rel="stylesheet" href="/site.css"><?php endif; ?>
        <script src="/assets/javascript/identity.js" defer></script>
        <script src="/assets/javascript/content.js" defer></script>
    </head>
    <body<?= isset($viewData['siteIdentity']) ? ' class="public-site"' : '' ?>>
        <a class="skip-link" href="#main-content">Skip to content</a>
        <?= $content ?>
    </body>
</html>

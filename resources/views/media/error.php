<?php declare(strict_types=1); ?>
<header class="site-header"><a class="brand" href="/"><span class="brand-mark" aria-hidden="true">N3</span><span>N3</span></a><a href="/account">Account</a></header>
<main class="error-page" id="main-content"><p class="eyebrow">Private media</p><h1>Media unavailable</h1><p><?= $escape($viewData['message'] ?? 'The Media library is temporarily unavailable.') ?></p><a class="button" href="/admin/media">Try again</a></main>

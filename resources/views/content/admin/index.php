<?php declare(strict_types=1); $pages = is_array($viewData['pages'] ?? null) ? $viewData['pages'] : []; $flash = is_array($viewData['flash'] ?? null) ? $viewData['flash'] : null; ?>
<header class="site-header"><a class="brand" href="/"><span class="brand-mark" aria-hidden="true">N3</span><span>N3</span></a><a href="/account">Account</a></header>
<main class="admin-page" id="main-content">
    <div class="admin-heading"><div><p class="eyebrow">Content</p><h1>Pages</h1><p>Draft, preview, and publish canonical pages.</p></div><a class="button" href="/admin/pages/create">Create page</a></div>
    <?php if ($flash !== null): ?><div class="alert alert-<?= $escape($flash['type']) ?>" role="status" tabindex="-1"><?= $escape($flash['message']) ?></div><?php endif; ?>
    <?php if ($pages === []): ?>
        <section class="empty-state" aria-labelledby="empty-title"><h2 id="empty-title">No pages yet</h2><p>Create the first draft to begin the CMS content flow.</p></section>
    <?php else: ?>
        <div class="page-list">
        <?php foreach ($pages as $page): ?>
            <article class="page-list-item">
                <div><span class="status-badge status-<?= $escape($page->status) ?>"><?= $escape(ucfirst($page->status)) ?></span><h2><a href="/admin/pages/<?= $escape($page->id) ?>/edit"><?= $escape($page->title) ?></a></h2><p>/pages/<?= $escape($page->slug) ?></p></div>
                <div class="page-list-actions"><a href="/admin/pages/<?= $escape($page->id) ?>/preview">Preview</a><a href="/admin/pages/<?= $escape($page->id) ?>/edit">Manage</a></div>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

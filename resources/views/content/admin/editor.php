<?php
declare(strict_types=1);
$page = $viewData['page'] ?? null;
$values = is_array($viewData['values'] ?? null) ? $viewData['values'] : [];
$errors = is_array($viewData['errors'] ?? null) ? $viewData['errors'] : [];
$flash = is_array($viewData['flash'] ?? null) ? $viewData['flash'] : null;
$creating = ($viewData['mode'] ?? '') === 'create';
$published = !$creating && $page->status === 'published';
$field = static fn (string $name): string => isset($errors[$name]) ? ' aria-invalid="true"' : '';
?>
<header class="site-header"><a class="brand" href="/"><span class="brand-mark" aria-hidden="true">N3</span><span>N3</span></a><a href="/admin/pages">All pages</a></header>
<main class="admin-page editor-page" id="main-content">
    <div class="admin-heading"><div><p class="eyebrow">Content editor</p><h1><?= $creating ? 'Create page' : $escape($page->title) ?></h1><?php if (!$creating): ?><p><span class="status-badge status-<?= $escape($page->status) ?>"><?= $escape(ucfirst($page->status)) ?></span> Version <?= $escape($page->lockVersion) ?></p><?php endif; ?></div><?php if (!$creating): ?><a class="button button-secondary" href="/admin/pages/<?= $escape($page->id) ?>/preview">Preview</a><?php endif; ?></div>
    <?php if ($flash !== null): ?><div class="alert alert-<?= $escape($flash['type']) ?>" role="status" tabindex="-1"><?= $escape($flash['message']) ?></div><?php endif; ?>
    <?php if (isset($errors['form'])): ?><div class="alert alert-warning" role="alert"><?= $escape($errors['form']) ?></div><?php endif; ?>
    <?php if ($published): ?><div class="alert alert-warning" role="status">Unpublish this page before editing its content.</div><?php endif; ?>
    <?php if (!$published): ?>
    <form class="editor-form" method="post" action="<?= $creating ? '/admin/pages' : '/admin/pages/' . $escape($page->id) ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= $escape($viewData['csrf']) ?>"><?php if (!$creating): ?><input type="hidden" name="lock_version" value="<?= $escape($page->lockVersion) ?>"><?php endif; ?>
        <div class="form-field"><label for="page-title">Title</label><input id="page-title" name="title" value="<?= $escape($values['title'] ?? '') ?>" maxlength="200" required aria-describedby="title-error"<?= $field('title') ?>><p class="field-error" id="title-error"><?= $escape($errors['title'] ?? '') ?></p></div>
        <div class="form-field"><label for="page-slug">Slug</label><div class="slug-field"><span aria-hidden="true">/pages/</span><input id="page-slug" name="slug" value="<?= $escape($values['slug'] ?? '') ?>" maxlength="160" pattern="[a-z0-9]+(-[a-z0-9]+)*" required aria-describedby="slug-help slug-error"<?= $creating ? ' data-slug-autofill="true"' : '' ?><?= $field('slug') ?>></div><p class="field-help" id="slug-help">Lowercase letters, numbers, and single hyphens.</p><p class="field-error" id="slug-error"><?= $escape($errors['slug'] ?? '') ?></p></div>
        <div class="form-field"><label for="page-excerpt">Excerpt <span class="optional">Optional</span></label><textarea id="page-excerpt" name="excerpt" rows="3" maxlength="500" aria-describedby="excerpt-help excerpt-error"<?= $field('excerpt') ?>><?= $escape($values['excerpt'] ?? '') ?></textarea><p class="field-help" id="excerpt-help">Used as the public page description. Maximum 500 characters.</p><p class="field-error" id="excerpt-error"><?= $escape($errors['excerpt'] ?? '') ?></p></div>
        <div class="form-field"><label for="page-body">Body</label><textarea id="page-body" name="body" rows="18" maxlength="100000" aria-describedby="body-help body-error"<?= $field('body') ?>><?= $escape($values['body'] ?? '') ?></textarea><p class="field-help" id="body-help">Plain text only in this milestone. Blank drafts are allowed; publishing requires content.</p><p class="field-error" id="body-error"><?= $escape($errors['body'] ?? '') ?></p></div>
        <button class="button" type="submit"><?= $creating ? 'Create draft' : 'Save draft' ?></button>
    </form>
    <?php endif; ?>
    <?php if (!$creating): ?>
    <section class="publication-panel" aria-labelledby="publication-title"><h2 id="publication-title">Publication</h2>
        <?php if ($published): ?><p>This page is public at <a href="/pages/<?= $escape($page->slug) ?>">/pages/<?= $escape($page->slug) ?></a>.</p><form method="post" action="/admin/pages/<?= $escape($page->id) ?>/unpublish"><input type="hidden" name="_csrf" value="<?= $escape($viewData['unpublishCsrf']) ?>"><input type="hidden" name="lock_version" value="<?= $escape($page->lockVersion) ?>"><button class="button button-warning" type="submit">Unpublish page</button></form>
        <?php else: ?><p>Publishing makes this draft available to everyone at its canonical slug.</p><form method="post" action="/admin/pages/<?= $escape($page->id) ?>/publish"><input type="hidden" name="_csrf" value="<?= $escape($viewData['publishCsrf']) ?>"><input type="hidden" name="lock_version" value="<?= $escape($page->lockVersion) ?>"><button class="button" type="submit">Publish page</button></form><?php endif; ?>
    </section>
    <?php endif; ?>
</main>

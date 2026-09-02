<?php
declare(strict_types=1);
use N3\Module\Blog\BlogPost;
$post = ($viewData['post'] ?? null) instanceof BlogPost ? $viewData['post'] : null;
$values = is_array($viewData['values'] ?? null) ? $viewData['values'] : [];
$errors = is_array($viewData['errors'] ?? null) ? $viewData['errors'] : [];
$flash = is_array($viewData['flash'] ?? null) ? $viewData['flash'] : null;
$creating = ($viewData['mode'] ?? '') === 'create';
$published = !$creating && $post?->status === 'published';
$field = static fn (string $name): string => isset($errors[$name]) ? ' aria-invalid="true"' : '';
?>
<header class="site-header"><a class="brand" href="/"><span class="brand-mark" aria-hidden="true">N3</span><span>N3</span></a><a href="/admin/blog">All Blog posts</a></header>
<main class="admin-page editor-page" id="main-content">
    <div class="admin-heading"><div><p class="eyebrow">Blog editor</p><h1><?= $creating ? 'Create Blog post' : $escape($post?->title ?? '') ?></h1><?php if (!$creating): ?><p><span class="status-badge status-<?= $escape($post?->status ?? '') ?>"><?= $escape(ucfirst($post?->status ?? '')) ?></span> Version <?= $escape($post?->lockVersion ?? '') ?></p><?php endif; ?></div><?php if (!$creating): ?><a class="button button-secondary" href="/admin/blog/<?= $escape($post?->id ?? '') ?>/preview">Preview</a><?php endif; ?></div>
    <?php if ($flash !== null): ?><div class="alert alert-<?= $escape($flash['type']) ?>" role="status" tabindex="-1"><?= $escape($flash['message']) ?></div><?php endif; ?>
    <?php if (isset($errors['form'])): ?><div class="alert alert-warning" role="alert"><?= $escape($errors['form']) ?></div><?php endif; ?>
    <?php if ($published): ?><div class="alert alert-warning" role="status">Unpublish this post before editing its content.</div><?php endif; ?>
    <?php if (!$published): ?><form class="editor-form" method="post" action="<?= $creating ? '/admin/blog' : '/admin/blog/' . $escape($post?->id ?? '') ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= $escape($viewData['csrf'] ?? '') ?>"><?php if (!$creating): ?><input type="hidden" name="lock_version" value="<?= $escape($post?->lockVersion ?? '') ?>"><?php endif; ?>
        <div class="form-field"><label for="blog-title">Title</label><input id="blog-title" name="title" value="<?= $escape($values['title'] ?? '') ?>" maxlength="200" required aria-describedby="blog-title-error"<?= $field('title') ?>><p class="field-error" id="blog-title-error"><?= $escape($errors['title'] ?? '') ?></p></div>
        <div class="form-field"><label for="blog-slug">Slug</label><div class="slug-field"><span aria-hidden="true">/blog/</span><input id="blog-slug" name="slug" value="<?= $escape($values['slug'] ?? '') ?>" maxlength="160" pattern="[a-z0-9]+(-[a-z0-9]+)*" required aria-describedby="blog-slug-help blog-slug-error"<?= $creating ? ' data-slug-autofill="true"' : '' ?><?= $field('slug') ?>></div><p class="field-help" id="blog-slug-help">Lowercase letters, numbers, and single hyphens.</p><p class="field-error" id="blog-slug-error"><?= $escape($errors['slug'] ?? '') ?></p></div>
        <div class="form-field"><label for="blog-excerpt">Excerpt <span class="optional">Optional</span></label><textarea id="blog-excerpt" name="excerpt" rows="3" maxlength="500" aria-describedby="blog-excerpt-help blog-excerpt-error"<?= $field('excerpt') ?>><?= $escape($values['excerpt'] ?? '') ?></textarea><p class="field-help" id="blog-excerpt-help">Used in the Blog index and public description. Maximum 500 characters.</p><p class="field-error" id="blog-excerpt-error"><?= $escape($errors['excerpt'] ?? '') ?></p></div>
        <div class="form-field"><label for="blog-body">Body</label><textarea id="blog-body" name="body" rows="18" maxlength="100000" aria-describedby="blog-body-help blog-body-error"<?= $field('body') ?>><?= $escape($values['body'] ?? '') ?></textarea><p class="field-help" id="blog-body-help">Plain text only. Blank drafts are allowed; publishing requires content.</p><p class="field-error" id="blog-body-error"><?= $escape($errors['body'] ?? '') ?></p></div>
        <button class="button" type="submit"><?= $creating ? 'Create draft' : 'Save draft' ?></button>
    </form><?php endif; ?>
    <?php if (!$creating): ?><section class="publication-panel" aria-labelledby="blog-publication"><h2 id="blog-publication">Publication</h2><?php if ($published): ?><p>This post is public at <a href="/blog/<?= $escape($post?->slug ?? '') ?>">/blog/<?= $escape($post?->slug ?? '') ?></a>.</p><form method="post" action="/admin/blog/<?= $escape($post?->id ?? '') ?>/unpublish"><input type="hidden" name="_csrf" value="<?= $escape($viewData['unpublishCsrf'] ?? '') ?>"><input type="hidden" name="lock_version" value="<?= $escape($post?->lockVersion ?? '') ?>"><button class="button button-warning" type="submit">Unpublish post</button></form><?php else: ?><p>Publishing adds this draft to the public Blog index.</p><form method="post" action="/admin/blog/<?= $escape($post?->id ?? '') ?>/publish"><input type="hidden" name="_csrf" value="<?= $escape($viewData['publishCsrf'] ?? '') ?>"><input type="hidden" name="lock_version" value="<?= $escape($post?->lockVersion ?? '') ?>"><button class="button" type="submit">Publish post</button></form><?php endif; ?></section><?php endif; ?>
</main>

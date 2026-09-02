<?php

declare(strict_types=1);

use N3\Module\Media\MediaLibraryItem;

$assets = is_array($viewData['assets'] ?? null) ? $viewData['assets'] : [];
$items = is_array($viewData['items'] ?? null) ? $viewData['items'] : [];
$lifecycleCsrf = is_array($viewData['lifecycleCsrf'] ?? null) ? $viewData['lifecycleCsrf'] : [];
$errors = is_array($viewData['errors'] ?? null) ? $viewData['errors'] : [];
$values = is_array($viewData['values'] ?? null) ? $viewData['values'] : ['label' => ''];
$flash = is_array($viewData['flash'] ?? null) ? $viewData['flash'] : null;
$csrf = (string) ($viewData['csrf'] ?? '');
?>
<header class="site-header"><a class="brand" href="/"><span class="brand-mark" aria-hidden="true">N3</span><span>N3</span></a><nav aria-label="Administration"><a href="/admin/pages">Pages</a><a href="/account">Account</a></nav></header>
<main class="admin-page media-page" id="main-content">
    <div class="admin-heading"><div><p class="eyebrow">Private content</p><h1>Media</h1><p>Sanitized image masters and previews. Raw uploads are never retained.</p></div></div>

    <?php if ($flash !== null): ?><div class="alert alert-<?= $escape($flash['type']) ?>" role="status" tabindex="-1"><?= $escape($flash['message']) ?></div><?php endif; ?>
    <?php if ($errors !== []): ?><div class="alert alert-warning" role="alert" tabindex="-1"><h2>Upload not completed</h2><ul><?php foreach ($errors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="media-upload" aria-labelledby="upload-heading">
        <div class="section-heading"><h2 id="upload-heading">Add an image</h2><p>JPEG or PNG, up to 10 MiB and 25 megapixels. N3 re-encodes the pixels as WebP and strips source metadata.</p></div>
        <form class="editor-form" method="post" action="/admin/media" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
            <div class="form-field"><label for="media-label">Library label</label><input id="media-label" name="label" type="text" required minlength="2" maxlength="120" autocomplete="off" value="<?= $escape($values['label'] ?? '') ?>"<?= isset($errors['label']) ? ' aria-invalid="true" aria-describedby="media-label-error"' : '' ?>><?php if (isset($errors['label'])): ?><p class="field-error" id="media-label-error"><?= $escape($errors['label']) ?></p><?php endif; ?></div>
            <div class="form-field"><label for="media-image">Image file</label><input id="media-image" name="image" type="file" required accept="image/jpeg,image/png"<?= isset($errors['image']) ? ' aria-invalid="true" aria-describedby="media-image-error"' : '' ?>><?php if (isset($errors['image'])): ?><p class="field-error" id="media-image-error"><?= $escape($errors['image']) ?></p><?php endif; ?></div>
            <button class="button auth-button" type="submit">Sanitize and add image</button>
        </form>
    </section>

    <section aria-labelledby="library-heading">
        <div class="section-heading"><h2 id="library-heading">Private library</h2><p><?= $escape(count($assets)) ?> most recent image<?= count($assets) === 1 ? '' : 's' ?>.</p></div>
        <?php if ($assets === []): ?>
            <div class="empty-state" role="status"><h3>No images yet</h3><p>Add the first image to validate the private Media workflow.</p></div>
        <?php else: ?>
            <div class="media-grid">
                <?php foreach ($items as $item): if (!$item instanceof MediaLibraryItem) { continue; } $asset = $item->asset; $tokens = $lifecycleCsrf[$asset->publicId] ?? []; ?>
                    <article class="media-card"><img src="/admin/media/<?= $escape($asset->publicId) ?>/preview" alt="" width="480" height="480" loading="lazy"><div><h3><?= $escape($asset->label) ?></h3><p><?= $escape($asset->width) ?> × <?= $escape($asset->height) ?> pixels · <?= $escape(number_format($asset->byteSize / 1024, 1)) ?> KiB</p><p>Added <time datetime="<?= $escape($asset->createdAt->format(DATE_ATOM)) ?>"><?= $escape($asset->createdAt->format('M j, Y H:i')) ?> UTC</time></p><p><strong><?= $escape($item->usage->attachments) ?> Page attachment<?= $item->usage->attachments === 1 ? '' : 's' ?></strong><?php if ($item->usage->publishedAttachments > 0): ?> · <?= $escape($item->usage->publishedAttachments) ?> published<?php endif; ?></p><div class="media-actions"><form method="post" action="/admin/media/<?= $escape($asset->publicId) ?>/regenerate"><input type="hidden" name="_csrf" value="<?= $escape($tokens['regenerate'] ?? '') ?>"><button class="button button-secondary" type="submit">Regenerate preview</button></form><form method="post" action="/admin/media/<?= $escape($asset->publicId) ?>/delete"><input type="hidden" name="_csrf" value="<?= $escape($tokens['delete'] ?? '') ?>"><button class="button button-warning" type="submit"<?= $item->usage->attachments > 0 ? ' disabled aria-describedby="usage-' . $escape($asset->publicId) . '"' : '' ?>>Delete unused image</button><?php if ($item->usage->attachments > 0): ?><span class="visually-hidden" id="usage-<?= $escape($asset->publicId) ?>">Deletion is unavailable while this image is attached.</span><?php endif; ?></form></div></div></article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

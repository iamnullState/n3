<?php declare(strict_types=1); use N3\App\Site\NavigationItem; use N3\App\Site\SiteIdentity; $identity = $viewData['identity']; $navigation = is_array($viewData['navigation'] ?? null) ? $viewData['navigation'] : []; $errors = is_array($viewData['errors'] ?? null) ? $viewData['errors'] : []; $flash = is_array($viewData['flash'] ?? null) ? $viewData['flash'] : null; ?>
<header class="site-header"><a class="brand" href="/"><span class="brand-mark" aria-hidden="true">N3</span><span>N3</span></a><nav aria-label="Administration"><a href="/admin/pages">Pages</a><a href="/account">Account</a></nav></header>
<main class="admin-page site-settings-page" id="main-content">
    <div class="admin-heading"><div><p class="eyebrow">White-label site</p><h1>Site settings</h1><p>Manage the public identity and ordered Page navigation.</p></div></div>
    <?php if ($flash !== null): ?><div class="alert alert-<?= $escape($flash['type']) ?>" role="status" tabindex="-1"><?= $escape($flash['message']) ?></div><?php endif; ?>
    <?php if ($errors !== []): ?><div class="alert alert-warning" role="alert" tabindex="-1"><h2>Settings not saved</h2><ul><?php foreach ($errors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (!$identity instanceof SiteIdentity): ?><p>Site settings are unavailable.</p><?php else: ?>
    <form class="editor-form" method="post" action="/admin/site">
        <input type="hidden" name="_csrf" value="<?= $escape($viewData['csrf'] ?? '') ?>"><input type="hidden" name="lock_version" value="<?= $escape($identity->lockVersion) ?>">
        <section aria-labelledby="identity-heading"><div class="section-heading"><h2 id="identity-heading">Site identity</h2><p>Public branding values are escaped and the logo remains a same-site asset.</p></div>
            <div class="form-field"><label for="site-name">Site name</label><input id="site-name" name="site_name" required minlength="2" maxlength="100" value="<?= $escape($identity->name) ?>"></div>
            <div class="form-field"><label for="tagline">Tagline</label><input id="tagline" name="tagline" maxlength="200" value="<?= $escape($identity->tagline) ?>"></div>
            <div class="form-field"><label for="contact-email">Contact email</label><input id="contact-email" name="contact_email" type="email" required maxlength="254" autocomplete="email" value="<?= $escape($identity->contactEmail) ?>"></div>
            <div class="form-field"><label for="primary-color">Primary brand color</label><input id="primary-color" name="primary_color" type="text" required pattern="#[0-9A-Fa-f]{6}" maxlength="7" value="<?= $escape($identity->primaryColor) ?>"><p class="field-hint">Six-digit hex with readable contrast against white.</p></div>
            <div class="form-field"><label for="logo-path">Optional logo asset path</label><input id="logo-path" name="logo_path" maxlength="255" placeholder="/assets/svg/brand.svg" value="<?= $escape($identity->logoPath) ?>"><p class="field-hint">Same-site SVG, PNG, JPEG, or WebP under /assets/photos or /assets/svg only.</p></div>
        </section>
        <section aria-labelledby="navigation-heading"><div class="section-heading"><h2 id="navigation-heading">Navigation</h2><p>Positions must be unique. Unpublished Pages remain hidden publicly even when enabled here.</p></div>
            <div class="navigation-editor">
            <?php foreach ($navigation as $index => $item): if (!$item instanceof NavigationItem) { continue; } $position = $item->position > 0 ? $item->position : (($index + 1) * 10); ?>
                <fieldset><legend><?= $escape($item->slug) ?> <span class="status-badge status-<?= $item->published ? 'published' : 'draft' ?>"><?= $item->published ? 'Published' : 'Draft' ?></span></legend>
                    <div class="form-field"><label for="nav-label-<?= $escape($item->pageId) ?>">Link label</label><input id="nav-label-<?= $escape($item->pageId) ?>" name="navigation[<?= $escape($item->pageId) ?>][label]" required maxlength="80" value="<?= $escape($item->label) ?>"></div>
                    <div class="form-field"><label for="nav-position-<?= $escape($item->pageId) ?>">Position</label><input id="nav-position-<?= $escape($item->pageId) ?>" name="navigation[<?= $escape($item->pageId) ?>][position]" type="number" min="1" max="65535" required value="<?= $escape($position) ?>"></div>
                    <label class="checkbox-row"><input name="navigation[<?= $escape($item->pageId) ?>][visible]" type="checkbox" value="1"<?= $item->visible ? ' checked' : '' ?>> Show in public navigation</label>
                </fieldset>
            <?php endforeach; ?>
            </div>
        </section>
        <button class="button auth-button" type="submit">Save site settings</button>
    </form>
    <?php endif; ?>
</main>

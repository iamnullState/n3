<?php

declare(strict_types=1);

$preflight = $viewData['preflight'] ?? null;
$checks = $preflight instanceof N3\App\Install\InstallerPreflight ? $preflight->checks : [];
$details = $preflight instanceof N3\App\Install\InstallerPreflight ? $preflight->details : [];
$errors = is_array($viewData['errors'] ?? null) ? $viewData['errors'] : [];
$old = is_array($viewData['old'] ?? null) ? $viewData['old'] : [];
$flash = is_array($viewData['flash'] ?? null) ? $viewData['flash'] : null;
$state = (string) ($viewData['state'] ?? 'migrations_pending');
$adminExists = ($viewData['adminExists'] ?? false) === true;
$labels = [
    'php' => 'PHP 8.5 or newer',
    'pdo_mysql' => 'PDO MySQL extension',
    'mbstring' => 'Multibyte string extension',
    'installer_secrets' => 'Independent security and installer secrets',
    'https' => 'HTTPS requirement',
    'private_storage' => 'Private storage permissions',
    'separate_database_accounts' => 'Separate runtime and migration accounts',
    'database' => 'Database connection',
    'module_extensions' => 'Enabled-module PHP extensions',
];
?>
<main class="admin-page install-page" id="main-content">
    <header class="admin-heading">
        <div><p class="eyebrow">Private setup</p><h1>Install this site</h1></div>
        <span class="status-badge"><?= $escape(str_replace('_', ' ', $state)) ?></span>
    </header>
    <?php if ($flash !== null): ?><div class="alert alert-<?= $escape($flash['type']) ?>" role="status" tabindex="-1"><?= $escape($flash['message']) ?></div><?php endif; ?>
    <?php if (is_string($viewData['error'] ?? null)): ?><div class="alert alert-warning" role="alert" tabindex="-1"><?= $escape($viewData['error']) ?></div><?php endif; ?>

    <?php if (($viewData['reopen'] ?? false) === true && $state === 'complete'): ?>
        <section class="empty-state" aria-labelledby="diagnostics-title"><h2 id="diagnostics-title">Installation diagnostics</h2><p>Installation is complete. Reopen mode is read-only; it cannot reset data or create another administrator.</p></section>
    <?php else: ?>
        <section class="editor-form install-section" aria-labelledby="requirements-title">
            <h2 id="requirements-title">Hosting requirements</h2>
            <ul class="install-checks">
                <?php foreach ($labels as $key => $label): $passed = ($checks[$key] ?? false) === true; ?>
                    <li><span class="status-badge status-<?= $passed ? 'published' : 'draft' ?>"><?= $passed ? 'Ready' : 'Action needed' ?></span> <?= $escape($label) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($details !== []): ?>
                <dl class="install-details">
                    <?php foreach ($details as $label => $value): ?><div><dt><?= $escape(str_replace('_', ' ', $label)) ?></dt><dd><?= $escape($value) ?></dd></div><?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </section>

        <?php if ($state === 'migrations_pending'): ?>
            <section class="publication-panel" aria-labelledby="database-title"><h2 id="database-title">Prepare the database</h2><p>Take a verified backup if this database is not empty. The process is resumable, but MariaDB schema changes can commit independently.</p>
                <form method="post" action="/install/migrate"><input type="hidden" name="_csrf" value="<?= $escape($viewData['csrfMigrate']) ?>"><button class="button" type="submit"<?= $preflight instanceof N3\App\Install\InstallerPreflight && $preflight->passes() ? '' : ' disabled' ?>>Run reviewed migrations</button></form>
            </section>
        <?php elseif ($state === 'pending_admin' && !$adminExists): ?>
            <section class="editor-form install-section" aria-labelledby="administrator-title"><h2 id="administrator-title">Create the administrator</h2><p>Creates one active, verified administrator. Passwords are never logged or retained outside the account hash.</p>
                <form method="post" action="/install/admin" novalidate><input type="hidden" name="_csrf" value="<?= $escape($viewData['csrfAdmin']) ?>">
                    <div class="form-field"><label for="install-name">Display name</label><input id="install-name" name="display_name" value="<?= $escape($old['display_name'] ?? '') ?>" minlength="2" maxlength="100" autocomplete="name" required autofocus aria-describedby="install-name-error"><p class="field-error" id="install-name-error"><?= $escape($errors['display_name'] ?? '') ?></p></div>
                    <div class="form-field"><label for="install-email">Email address</label><input id="install-email" name="email" type="email" value="<?= $escape($old['email'] ?? '') ?>" maxlength="254" autocomplete="email" required aria-describedby="install-email-error"><p class="field-error" id="install-email-error"><?= $escape($errors['email'] ?? '') ?></p></div>
                    <div class="form-field"><label for="install-password">Password</label><div class="password-field"><input id="install-password" name="password" type="password" minlength="12" autocomplete="new-password" required aria-describedby="install-password-help install-password-error"><button type="button" class="password-toggle" data-password-toggle="install-password" aria-pressed="false">Show</button></div><p class="field-help" id="install-password-help">Use a unique passphrase of at least 12 characters.</p><p class="field-error" id="install-password-error"><?= $escape($errors['password'] ?? '') ?></p></div>
                    <div class="form-field"><label for="install-confirmation">Confirm password</label><div class="password-field"><input id="install-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required aria-describedby="install-confirmation-error"><button type="button" class="password-toggle" data-password-toggle="install-confirmation" aria-pressed="false">Show</button></div><p class="field-error" id="install-confirmation-error"><?= $escape($errors['password_confirmation'] ?? '') ?></p></div>
                    <button class="button auth-button" type="submit">Create administrator and finish</button>
                </form>
            </section>
        <?php elseif ($state === 'pending_admin' && $adminExists): ?>
            <section class="publication-panel" aria-labelledby="finish-title"><h2 id="finish-title">Finish interrupted setup</h2><p>An administrator already exists. Finalize without creating another account.</p><form method="post" action="/install/complete"><input type="hidden" name="_csrf" value="<?= $escape($viewData['csrfComplete']) ?>"><button class="button" type="submit">Finish installation</button></form></section>
        <?php endif; ?>
    <?php endif; ?>
</main>

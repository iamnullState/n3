<?php declare(strict_types=1); $errors = is_array($viewData['errors'] ?? null) ? $viewData['errors'] : []; ?>
<header class="site-header"><a class="brand" href="/"><span class="brand-mark" aria-hidden="true">N3</span><span>N3</span></a><a href="/login">Sign in</a></header>
<main class="auth-page" id="main-content"><article class="auth-card" aria-labelledby="reset-title"><p class="eyebrow">Account recovery</p><h1 id="reset-title">Choose a new password</h1>
<?php if (isset($errors['form'])): ?><div class="alert alert-warning" role="alert"><?= $escape($errors['form']) ?></div><?php endif; ?>
<?php if (!($viewData['hasToken'] ?? false)): ?><div class="alert alert-warning" role="status">Open the reset link from your recovery message.</div><?php else: ?>
<form method="post" action="/reset-password" novalidate><input type="hidden" name="_csrf" value="<?= $escape($viewData['csrf']) ?>">
<div class="form-field"><label for="reset-password">New password</label><div class="password-field"><input id="reset-password" name="password" type="password" autocomplete="new-password" minlength="12" required aria-describedby="reset-password-help reset-password-error" autofocus><button type="button" class="password-toggle" data-password-toggle="reset-password" aria-pressed="false">Show</button></div><p class="field-help" id="reset-password-help">Use a unique passphrase of at least 12 characters.</p><p class="field-error" id="reset-password-error"><?= $escape($errors['password'] ?? '') ?></p></div>
<div class="form-field"><label for="reset-confirmation">Confirm new password</label><input id="reset-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required aria-describedby="reset-confirmation-error"><p class="field-error" id="reset-confirmation-error"><?= $escape($errors['password_confirmation'] ?? '') ?></p></div>
<button class="button auth-button" type="submit">Change password</button></form><?php endif; ?>
</article></main>

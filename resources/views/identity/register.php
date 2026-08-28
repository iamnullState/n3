<?php

declare(strict_types=1);

$errors = is_array($viewData['errors'] ?? null) ? $viewData['errors'] : [];
$old = is_array($viewData['old'] ?? null) ? $viewData['old'] : [];
$flash = is_array($viewData['flash'] ?? null) ? $viewData['flash'] : null;
?>
<header class="site-header">
    <a class="brand" href="/"><span class="brand-mark" aria-hidden="true">N3</span><span>N3</span></a>
    <a href="/login">Sign in</a>
</header>
<main class="auth-page" id="main-content">
    <article class="auth-card" aria-labelledby="register-title">
        <p class="eyebrow">Member access</p>
        <h1 id="register-title">Create an account</h1>
        <p class="auth-intro">Register as a member, then verify your email before signing in.</p>
        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= $escape($flash['type']) ?>" role="status" tabindex="-1"><?= $escape($flash['message']) ?></div>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?>
            <div class="alert alert-warning" role="alert"><?= $escape($errors['form']) ?></div>
        <?php endif; ?>
        <?php if (!($viewData['registrationEnabled'] ?? false)): ?>
            <div class="alert alert-warning" role="status">Registration is currently disabled.</div>
        <?php else: ?>
            <form method="post" action="/register" novalidate>
                <input type="hidden" name="_csrf" value="<?= $escape($viewData['csrf']) ?>">
                <div class="form-field">
                    <label for="display-name">Display name</label>
                    <input id="display-name" name="display_name" value="<?= $escape($old['display_name'] ?? '') ?>" autocomplete="name" minlength="2" maxlength="100" required aria-describedby="display-name-error">
                    <p class="field-error" id="display-name-error"><?= $escape($errors['display_name'] ?? '') ?></p>
                </div>
                <div class="form-field">
                    <label for="register-email">Email address</label>
                    <input id="register-email" name="email" type="email" value="<?= $escape($old['email'] ?? '') ?>" autocomplete="email" maxlength="254" required aria-describedby="register-email-error">
                    <p class="field-error" id="register-email-error"><?= $escape($errors['email'] ?? '') ?></p>
                </div>
                <div class="form-field">
                    <label for="register-password">Password</label>
                    <div class="password-field">
                        <input id="register-password" name="password" type="password" autocomplete="new-password" minlength="12" required aria-describedby="password-help password-error">
                        <button type="button" class="password-toggle" data-password-toggle="register-password" aria-pressed="false">Show</button>
                    </div>
                    <p class="field-help" id="password-help">Use a unique passphrase of at least 12 characters.</p>
                    <p class="field-error" id="password-error"><?= $escape($errors['password'] ?? '') ?></p>
                </div>
                <div class="form-field">
                    <label for="password-confirmation">Confirm password</label>
                    <div class="password-field">
                        <input id="password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required aria-describedby="password-confirmation-error">
                        <button type="button" class="password-toggle" data-password-toggle="password-confirmation" aria-pressed="false">Show</button>
                    </div>
                    <p class="field-error" id="password-confirmation-error"><?= $escape($errors['password_confirmation'] ?? '') ?></p>
                </div>
                <button class="button auth-button" type="submit">Create account</button>
            </form>
        <?php endif; ?>
    </article>
</main>

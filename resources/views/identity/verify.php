<?php

declare(strict_types=1);

$flash = is_array($viewData['flash'] ?? null) ? $viewData['flash'] : null;
?>
<header class="site-header">
    <a class="brand" href="/"><span class="brand-mark" aria-hidden="true">N3</span><span>N3</span></a>
    <a href="/register">Create account</a>
</header>
<main class="auth-page" id="main-content">
    <article class="auth-card" aria-labelledby="verify-title">
        <p class="eyebrow">Account security</p>
        <h1 id="verify-title">Verify your email</h1>
        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= $escape($flash['type']) ?>" role="status"><?= $escape($flash['message']) ?></div>
        <?php endif; ?>
        <?php if ($viewData['hasToken'] ?? false): ?>
            <p>Confirm that you want to verify this account.</p>
            <form method="post" action="/verify-email">
                <input type="hidden" name="_csrf" value="<?= $escape($viewData['csrf']) ?>">
                <button class="button auth-button" type="submit">Verify email</button>
            </form>
        <?php else: ?>
            <p>Open the private verification link created for your registration.</p>
        <?php endif; ?>
        <hr>
        <h2>Need a new message?</h2>
        <form method="post" action="/verify-email/resend">
            <input type="hidden" name="_csrf" value="<?= $escape($viewData['resendCsrf']) ?>">
            <label for="resend-email">Email address</label>
            <input id="resend-email" name="email" type="email" autocomplete="email" required>
            <button class="button auth-button" type="submit">Request verification</button>
        </form>
    </article>
</main>

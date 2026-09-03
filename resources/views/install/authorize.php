<?php declare(strict_types=1); ?>
<main class="auth-page install-page" id="main-content">
    <article class="auth-card" aria-labelledby="setup-access-title">
        <p class="eyebrow">Private setup</p>
        <h1 id="setup-access-title">Installation access</h1>
        <p class="auth-intro">Enter the one-time installer token configured by your hosting environment.</p>
        <?php if (is_string($viewData['error'] ?? null)): ?>
            <div class="alert alert-warning" role="alert" tabindex="-1"><?= $escape($viewData['error']) ?></div>
        <?php endif; ?>
        <form method="post" action="/install/authorize" autocomplete="off">
            <div class="form-field">
                <label for="install-token">Installer token</label>
                <input id="install-token" name="install_token" type="password" required autofocus autocomplete="off">
            </div>
            <button class="button auth-button" type="submit">Continue</button>
        </form>
    </article>
</main>

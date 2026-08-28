<?php declare(strict_types=1); $flash = is_array($viewData['flash'] ?? null) ? $viewData['flash'] : null; ?>
<header class="site-header"><a class="brand" href="/"><span class="brand-mark" aria-hidden="true">N3</span><span>N3</span></a><a href="/register">Register</a></header>
<main class="auth-page" id="main-content"><article class="auth-card" aria-labelledby="login-title">
<p class="eyebrow">Member access</p><h1 id="login-title">Sign in</h1>
<?php if ($flash !== null): ?><div class="alert alert-<?= $escape($flash['type']) ?>" role="status" tabindex="-1"><?= $escape($flash['message']) ?></div><?php endif; ?>
<?php if (is_string($viewData['error'] ?? null)): ?><div class="alert alert-warning" role="alert"><?= $escape($viewData['error']) ?></div><?php endif; ?>
<form method="post" action="/login" novalidate><input type="hidden" name="_csrf" value="<?= $escape($viewData['csrf']) ?>">
<div class="form-field"><label for="login-email">Email address</label><input id="login-email" name="email" type="email" value="<?= $escape($viewData['email'] ?? '') ?>" autocomplete="email" maxlength="254" required autofocus></div>
<div class="form-field"><label for="login-password">Password</label><div class="password-field"><input id="login-password" name="password" type="password" autocomplete="current-password" required><button type="button" class="password-toggle" data-password-toggle="login-password" aria-pressed="false">Show</button></div></div>
<button class="button auth-button" type="submit">Sign in</button></form><p><a href="/forgot-password">Forgot your password?</a></p>
</article></main>

<?php declare(strict_types=1); $flash = is_array($viewData['flash'] ?? null) ? $viewData['flash'] : null; ?>
<header class="site-header"><a class="brand" href="/"><span class="brand-mark" aria-hidden="true">N3</span><span>N3</span></a><a href="/login">Sign in</a></header>
<main class="auth-page" id="main-content"><article class="auth-card" aria-labelledby="forgot-title"><p class="eyebrow">Account recovery</p><h1 id="forgot-title">Forgot your password?</h1>
<p class="auth-intro">Enter your email address. The response is the same whether or not an account exists.</p>
<?php if ($flash !== null): ?><div class="alert alert-<?= $escape($flash['type']) ?>" role="status" tabindex="-1"><?= $escape($flash['message']) ?></div><?php endif; ?>
<form method="post" action="/forgot-password"><input type="hidden" name="_csrf" value="<?= $escape($viewData['csrf']) ?>"><div class="form-field"><label for="recovery-email">Email address</label><input id="recovery-email" name="email" type="email" autocomplete="email" maxlength="254" required autofocus></div><button class="button auth-button" type="submit">Request reset</button></form>
</article></main>

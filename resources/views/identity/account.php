<?php declare(strict_types=1); $user = $viewData['user']; ?>
<header class="site-header"><a class="brand" href="/"><span class="brand-mark" aria-hidden="true">N3</span><span>N3</span></a></header>
<main class="auth-page" id="main-content"><article class="auth-card" aria-labelledby="account-title"><p class="eyebrow">Account</p>
<h1 id="account-title">Welcome, <?= $escape($user->displayName) ?></h1><p>Signed in as <?= $escape($user->email) ?>.</p>
<form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= $escape($viewData['logoutCsrf']) ?>"><button class="button auth-button" type="submit">Sign out</button></form>
</article></main>

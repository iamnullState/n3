<?php declare(strict_types=1); $message = (string) ($viewData['message'] ?? 'Analytics is unavailable.'); ?>
<header class="site-header"><a class="brand" href="/"><span class="brand-mark" aria-hidden="true">N3</span><span>N3</span></a><a href="/account">Account</a></header>
<main class="admin-page" id="main-content">
    <p class="eyebrow">Analytics</p>
    <h1>Report unavailable</h1>
    <div class="alert alert-warning" role="alert"><p><?= $escape($message) ?></p></div>
    <p><a href="/admin/analytics">Return to Analytics</a></p>
</main>

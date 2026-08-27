<?php

declare(strict_types=1);

/** @var array<string, mixed> $viewData */
/** @var Closure(mixed): string $escape */
?>
<header class="site-header">
    <a class="brand" href="/" aria-label="<?= $escape($viewData['appName']) ?> home">
        <span class="brand-mark" aria-hidden="true">N3</span>
        <span><?= $escape($viewData['appName']) ?></span>
    </a>
    <span class="release-badge">Core <?= $escape($viewData['version']) ?></span>
</header>

<main id="main-content">
    <section class="hero" aria-labelledby="hero-title">
        <p class="eyebrow">White-label CMS framework</p>
        <h1 class="greeting" id="hero-title">
            <span class="greeting-hello">Hello,</span>
            <span class="greeting-world">world.</span>
        </h1>
        <p class="hero-copy">
            One focused PHP core. Independent installations. Modules that add capability without duplicating the codebase.
        </p>
        <a class="button" href="#foundation">Explore the foundation</a>
    </section>

    <section class="foundation" id="foundation" aria-labelledby="foundation-title">
        <div class="section-heading">
            <p class="eyebrow">The first foundation</p>
            <h2 id="foundation-title">Small core. Clear boundaries.</h2>
            <p>N3 starts with a secure request lifecycle before databases, accounts, modules, or external services are added.</p>
        </div>

        <div class="feature-grid">
            <article class="feature-card">
                <span class="feature-number" aria-hidden="true">01</span>
                <h3>Secure by default</h3>
                <p>Escaped views, strict response headers, private runtime storage, and failure paths that do not expose internals.</p>
            </article>
            <article class="feature-card">
                <span class="feature-number" aria-hidden="true">02</span>
                <h3>Modular by contract</h3>
                <p>Core stays independent. Optional capabilities integrate through explicit interfaces, events, and service boundaries.</p>
            </article>
            <article class="feature-card">
                <span class="feature-number" aria-hidden="true">03</span>
                <h3>Built to adapt</h3>
                <p>Each brand receives an isolated installation while the maintained Core remains one coherent codebase.</p>
            </article>
        </div>
    </section>
</main>

<footer class="site-footer">
    <p><strong><?= $escape($viewData['appName']) ?></strong> Core <?= $escape($viewData['version']) ?></p>
    <p>Landing view · <?= $escape($viewData['environment']) ?> environment</p>
</footer>

<?php

declare(strict_types=1);

use N3\Module\Analytics\AnalyticsReport;
use N3\Module\Analytics\VitalAssessment;

$report = $viewData['report'] ?? null;
$days = (int) ($viewData['days'] ?? 7);
$periods = is_array($viewData['periods'] ?? null) ? $viewData['periods'] : [];
$assessments = is_array($viewData['assessments'] ?? null) ? $viewData['assessments'] : [];
if (!$report instanceof AnalyticsReport) { throw new RuntimeException('Analytics dashboard report data is invalid.'); }
?>
<header class="site-header"><a class="brand" href="/"><span class="brand-mark" aria-hidden="true">N3</span><span>N3</span></a><nav aria-label="Administration"><a href="/admin/pages">Pages</a><a href="/account">Account</a></nav></header>
<main class="admin-page analytics-page" id="main-content">
    <div class="admin-heading"><div><p class="eyebrow">Private operations</p><h1>Analytics</h1><p>Hourly aggregate traffic and development vitals. No visitor-level tracking.</p></div></div>

    <nav class="period-selector" aria-label="Report period">
        <?php foreach ($periods as $period): ?><a href="/admin/analytics?days=<?= $escape($period) ?>"<?= $period === $days ? ' aria-current="page"' : '' ?>><?= $escape($period) ?> day<?= $period === 1 ? '' : 's' ?></a><?php endforeach; ?>
    </nav>

    <p class="report-window">Completed hourly buckets from <time datetime="<?= $escape($report->since->format(DATE_ATOM)) ?>"><?= $escape($report->since->format('M j, Y H:i')) ?> UTC</time> to <time datetime="<?= $escape($report->until->format(DATE_ATOM)) ?>"><?= $escape($report->until->format('M j, Y H:i')) ?> UTC</time>.</p>

    <section aria-labelledby="vitals-heading">
        <div class="section-heading"><h2 id="vitals-heading">Vitals portfolio</h2><p>Development budgets indicate regressions; they are not production service-level objectives.</p></div>
        <div class="vitals-grid">
            <?php foreach ($assessments as $assessment): if (!$assessment instanceof VitalAssessment) { continue; } ?>
                <article class="vital-card vital-<?= $escape($assessment->status) ?>">
                    <div class="vital-heading"><h3><?= $escape($assessment->label) ?></h3><span class="vital-status"><?= $escape($assessment->status === 'no-data' ? 'No data' : ucfirst($assessment->status)) ?></span></div>
                    <p class="vital-value"><?= $escape($assessment->currentValue) ?></p>
                    <p><strong>Budget:</strong> <?= $escape($assessment->target) ?></p>
                    <p><?= $escape($assessment->description) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="budget-note">Rendered dashboard HTML is independently gated at 32 KiB in automated tests.</p>
    </section>

    <section class="analytics-table-section" aria-labelledby="routes-heading">
        <div class="section-heading"><h2 id="routes-heading">Route categories</h2><p><?= $escape(number_format($report->requestCount())) ?> total requests; <?= $escape(number_format($report->serverErrorCount())) ?> returned 5xx.</p></div>
        <?php if ($report->routes === []): ?>
            <div class="empty-state" role="status"><h3>No aggregate data</h3><p>No completed hourly buckets are available for this period.</p></div>
        <?php else: ?>
            <div class="table-scroll" tabindex="0" role="region" aria-label="Scrollable route category report">
                <table class="analytics-table">
                    <caption>Aggregate request outcomes by controlled route category</caption>
                    <thead><tr><th scope="col">Route category</th><th scope="col">Requests</th><th scope="col">5xx</th><th scope="col">Average</th><th scope="col">Maximum</th></tr></thead>
                    <tbody><?php foreach ($report->routes as $route): ?><tr><th scope="row"><code><?= $escape($route->routeCategory) ?></code></th><td><?= $escape(number_format($route->requestCount)) ?></td><td><?= $escape(number_format($route->serverErrorCount)) ?></td><td><?= $escape(number_format($route->averageDurationMicroseconds() / 1000, 1)) ?> ms</td><td><?= $escape(number_format($route->maximumDurationMicroseconds / 1000, 1)) ?> ms</td></tr><?php endforeach; ?></tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

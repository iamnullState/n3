<?php

declare(strict_types=1);

namespace N3\Module\Analytics;

use Closure;
use N3\Core\Observability\RequestMetric;
use N3\Core\Observability\RequestMetricsSink;
use RuntimeException;

final class LazyAnalyticsSink implements RequestMetricsSink
{
    private ?RequestMetricsSink $sink = null;

    /** @param Closure(): RequestMetricsSink $factory */
    public function __construct(private readonly Closure $factory)
    {
    }

    public function record(RequestMetric $metric): void
    {
        if ($this->sink === null) {
            $sink = ($this->factory)();
            if (!$sink instanceof RequestMetricsSink) {
                throw new RuntimeException('The Analytics sink factory returned an invalid service.');
            }
            $this->sink = $sink;
        }

        $this->sink->record($metric);
    }
}

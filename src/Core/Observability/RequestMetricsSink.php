<?php

declare(strict_types=1);

namespace N3\Core\Observability;

interface RequestMetricsSink
{
    public function record(RequestMetric $metric): void;
}

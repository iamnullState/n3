<?php

declare(strict_types=1);

namespace N3\Module\Analytics;

final readonly class AnalyticsVitals
{
    private const AVERAGE_RESPONSE_BUDGET_US = 250_000;
    private const MAXIMUM_RESPONSE_BUDGET_US = 2_000_000;
    private const SERVER_ERROR_RATE_BUDGET = 0.01;
    private const REPORT_QUERY_BUDGET_US = 100_000;
    private const CSS_BUDGET_BYTES = 20_480;
    private const JAVASCRIPT_BUDGET_BYTES = 5_120;

    public function __construct(private string $projectRoot)
    {
    }

    /** @return list<VitalAssessment> */
    public function assess(AnalyticsReport $report, int $reportQueryMicroseconds): array
    {
        $hasRequests = $report->requestCount() > 0;

        return [
            new VitalAssessment(
                'Average response',
                $hasRequests ? $this->milliseconds($report->averageDurationMicroseconds()) : 'No data',
                '≤ 250 ms',
                $hasRequests ? $this->status($report->averageDurationMicroseconds(), self::AVERAGE_RESPONSE_BUDGET_US) : 'no-data',
                'Mean server request duration across the selected aggregate window.',
            ),
            new VitalAssessment(
                'Maximum response',
                $hasRequests ? $this->milliseconds($report->maximumDurationMicroseconds()) : 'No data',
                '≤ 2,000 ms',
                $hasRequests ? $this->status($report->maximumDurationMicroseconds(), self::MAXIMUM_RESPONSE_BUDGET_US) : 'no-data',
                'Slowest aggregated request observed in the selected window.',
            ),
            new VitalAssessment(
                'Server error rate',
                $hasRequests ? sprintf('%.2f%%', $report->serverErrorRate() * 100) : 'No data',
                '≤ 1.00%',
                $hasRequests ? $this->status($report->serverErrorRate(), self::SERVER_ERROR_RATE_BUDGET) : 'no-data',
                'Share of requests that returned a 5xx response.',
            ),
            new VitalAssessment(
                'Report query',
                $this->milliseconds($reportQueryMicroseconds),
                '≤ 100 ms',
                $this->status($reportQueryMicroseconds, self::REPORT_QUERY_BUDGET_US),
                'Transient MariaDB time for this bounded aggregate report.',
            ),
            $this->assetAssessment(
                'Application CSS',
                [$this->projectRoot . '/public/assets/css/app.css'],
                self::CSS_BUDGET_BYTES,
                '≤ 20 KiB',
            ),
            $this->assetAssessment(
                'Application JavaScript',
                [
                    $this->projectRoot . '/public/assets/javascript/content.js',
                    $this->projectRoot . '/public/assets/javascript/identity.js',
                ],
                self::JAVASCRIPT_BUDGET_BYTES,
                '≤ 5 KiB',
            ),
        ];
    }

    /** @param list<string> $paths */
    private function assetAssessment(string $label, array $paths, int $budget, string $target): VitalAssessment
    {
        $bytes = 0;
        foreach ($paths as $path) {
            $size = is_file($path) ? filesize($path) : false;
            if ($size === false) {
                return new VitalAssessment(
                    $label,
                    'Unavailable',
                    $target,
                    'attention',
                    'An allowlisted public asset could not be measured.',
                );
            }
            $bytes += $size;
        }

        return new VitalAssessment(
            $label,
            sprintf('%.1f KiB', $bytes / 1024),
            $target,
            $this->status($bytes, $budget),
            'Combined bytes of the allowlisted first-party application asset files.',
        );
    }

    private function milliseconds(int $microseconds): string
    {
        return sprintf('%.1f ms', $microseconds / 1000);
    }

    private function status(int|float $current, int|float $budget): string
    {
        return $current <= $budget ? 'pass' : 'attention';
    }
}

<?php

declare(strict_types=1);

namespace N3\Module\Analytics;

use InvalidArgumentException;

final readonly class VitalAssessment
{
    public function __construct(
        public string $label,
        public string $currentValue,
        public string $target,
        public string $status,
        public string $description,
    ) {
        if (!in_array($status, ['pass', 'attention', 'no-data'], true)) {
            throw new InvalidArgumentException('Vital assessment status is invalid.');
        }
    }
}

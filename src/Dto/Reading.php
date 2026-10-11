<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\HealthMetricType;

/**
 * One measurement as a source reports it, before it is stored (see CONTEXT.md):
 * the value is in the source's own unit ($unit, '' when the source didn't say),
 * and $recordedAt is the source's local wall-clock time.
 */
final readonly class Reading
{
    public function __construct(
        public HealthMetricType $type,
        public float $value,
        public string $unit,
        public \DateTimeImmutable $recordedAt,
        public string $source,
    ) {
    }
}

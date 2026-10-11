<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a metric type's readings are summarized per time bucket on the Health
 * Metrics page. Drives both the SQL aggregate and the card sublabel from one
 * place, so the number shown and the label describing it can't drift apart
 * (the bug this fixes: steps were summed-in-reality but labelled "averages").
 */
enum MetricSummary
{
    case Total;    // summed per bucket — cumulative counts (steps)
    case Average;  // mean per bucket, with a meaningful min/max range (glucose, HR, BP)
    case Reading;  // a point measurement, ~one per bucket (weight, body fat %)

    /** SQL aggregate for the headline value: SUM for totals, AVG otherwise. */
    public function valueAggregate(): string
    {
        return $this === self::Total ? 'SUM' : 'AVG';
    }

    /**
     * Whether the min/max range collapses to the headline value (so the chart draws
     * no band). Only a Total collapses — a sum has no spread. Average and Reading
     * both keep their real min/max (a Reading's band just naturally disappears when
     * there's a single reading in the bucket).
     */
    public function collapsesRange(): bool
    {
        return $this === self::Total;
    }

    /** Card sublabel describing the per-bucket aggregation, e.g. "daily totals". */
    public function bucketLabel(string $granularity): string
    {
        $adj = $granularity === 'hour' ? 'hourly' : 'daily';

        return match ($this) {
            self::Total   => "{$adj} totals",
            self::Average => "{$adj} averages, range shaded",
            self::Reading => "{$adj} readings",
        };
    }

    /** Translation key for bucketLabel() (i18n); the enum can't inject the translator. */
    public function bucketTransKey(string $granularity): string
    {
        $adj = $granularity === 'hour' ? 'hourly' : 'daily';

        return 'metric_summary.' . strtolower($this->name) . '_' . $adj;
    }
}

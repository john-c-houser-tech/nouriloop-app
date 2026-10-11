<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a metric is rolled up across the evaluation window before being compared
 * to the target.
 */
enum GoalAggregation: string
{
    /** Mean of each day's total, over days that have meals logged. */
    case DailyAverage = 'daily_average';

    /** Sum across the whole window (e.g. total protein over a week). */
    case WindowTotal = 'window_total';

    public function label(): string
    {
        return match ($this) {
            self::DailyAverage => 'daily average',
            self::WindowTotal  => 'window total',
        };
    }

    /** Translation key for UI rendering (i18n); label() stays for PHP contexts. */
    public function transKey(): string
    {
        return 'goal.aggregation.' . $this->value;
    }
}

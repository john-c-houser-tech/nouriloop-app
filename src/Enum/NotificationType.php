<?php

declare(strict_types=1);

namespace App\Enum;

enum NotificationType: string
{
    case NoMealsToday = 'no_meals_today';
    case StaleMetrics = 'stale_metrics';
    case CalorieRange = 'calorie_range';
    case Supplements = 'supplements';

    public function label(): string
    {
        return match ($this) {
            self::NoMealsToday => 'No meals logged today',
            self::StaleMetrics => 'Stale health-metrics reminder',
            self::CalorieRange => 'Calorie out-of-range alert',
            self::Supplements  => 'Weekly supplement suggestions',
        };
    }

    /**
     * Minimum number of days between two notifications of this type to the same user.
     */
    public function cooldownDays(): int
    {
        return match ($this) {
            self::NoMealsToday => 1,
            self::StaleMetrics => 7,
            self::CalorieRange => 1,
            self::Supplements  => 7,
        };
    }
}

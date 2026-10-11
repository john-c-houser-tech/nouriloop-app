<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How often a goal's scheduled check-in runs.
 */
enum GoalFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Daily  => 'Daily',
            self::Weekly => 'Weekly',
        };
    }

    /** Translation key for UI rendering (i18n); label() stays for PHP contexts. */
    public function transKey(): string
    {
        return 'goal.frequency.' . $this->value;
    }
}

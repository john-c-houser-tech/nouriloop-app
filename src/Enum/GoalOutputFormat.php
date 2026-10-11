<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a scheduled check-in produces for the user.
 */
enum GoalOutputFormat: string
{
    case Summary = 'summary';
    case Chart = 'chart';
    case Report = 'report';

    public function label(): string
    {
        return match ($this) {
            self::Summary => 'Text summary',
            self::Chart   => 'Summary with chart',
            self::Report  => 'Full report',
        };
    }

    /** Whether this format includes a generated chart image. */
    public function includesChart(): bool
    {
        return $this === self::Chart || $this === self::Report;
    }

    /** Translation key for UI rendering (i18n); label() stays for PHP contexts. */
    public function transKey(): string
    {
        return 'goal.output.' . $this->value;
    }
}

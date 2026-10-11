<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Outcome of a single scheduled evaluation, decided deterministically in PHP
 * (the Claude narrative only describes it).
 */
enum GoalCheckinStatus: string
{
    case Achieved = 'achieved';
    case OnTrack = 'on_track';
    case OffTrack = 'off_track';
    case NoData = 'no_data';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Achieved => 'Goal achieved',
            self::OnTrack  => 'On track',
            self::OffTrack => 'Off track',
            self::NoData   => 'No meals logged',
            self::Error    => 'Evaluation failed',
        };
    }

    /**
     * Whether a check-in with this status is worth sending. No-data ("you logged
     * nothing") and error check-ins are recorded for the in-app history but not
     * emailed, so a disengaged user isn't nagged on every scheduled run.
     */
    public function isDeliverable(): bool
    {
        return $this !== self::NoData && $this !== self::Error;
    }

    /** Bootstrap contextual color for badges. */
    public function badge(): string
    {
        return match ($this) {
            self::Achieved => 'success',
            self::OnTrack  => 'info',
            self::OffTrack => 'warning',
            self::NoData   => 'secondary',
            self::Error    => 'danger',
        };
    }

    /** Translation key for UI rendering (i18n); label() stays for PHP contexts. */
    public function transKey(): string
    {
        return 'goal.status.' . $this->value;
    }
}

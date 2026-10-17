<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which monthly AI-analysis allowance a user is on (see AnalysisAllowance), and so
 * what the counter says and where a user who has used it up is sent. Users with no
 * allowance (a self-hosted copy, or a paid plan with their own key) have no kind.
 */
enum AllowanceKind: string
{
    /** The Free plan's starter allowance; more comes with a paid plan. */
    case Free = 'free';
    /** Loop before a key is saved: the starter allowance until they add their own. */
    case KeyPending = 'key_pending';
    /** Loop+: the analyses its price includes (+ top-ups); their own key lifts the cap. */
    case Included = 'included';

    /** "N of M … left this month", with remaining and limit parameters. */
    public function counterKey(): string
    {
        return match ($this) {
            self::Free       => 'meal.log.allowance_left',
            self::KeyPending => 'meal.log.allowance_left_key_pending',
            self::Included   => 'meal.log.allowance_left_included',
        };
    }

    /** The link shown beside the counter once 80% is used (AllowanceStatus::isLow()). */
    public function linkKey(): string
    {
        return match ($this) {
            self::Free       => 'meal.log.allowance_upgrade',
            self::KeyPending => 'meal.log.allowance_add_key',
            self::Included   => 'meal.log.allowance_more',
        };
    }

    /** The flash shown when the allowance is used up, with a limit parameter. */
    public function limitReachedKey(): string
    {
        return match ($this) {
            self::Free       => 'meal.log.limit_reached',
            self::KeyPending => 'meal.log.limit_reached_key_pending',
            self::Included   => 'meal.log.limit_reached_included',
        };
    }

    /** Where the counter's link and the used-up redirect go. */
    public function route(): string
    {
        return match ($this) {
            self::Free       => 'app_pricing',
            self::KeyPending => 'app_settings_ai',
            // The allowance page: buy a top-up or add their own key.
            self::Included   => 'app_allowance',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which side of the target counts as success. "Eat less sugar" is AtMost a
 * target; "more fiber" is AtLeast a target.
 */
enum GoalDirection: string
{
    case AtMost = 'at_most';
    case AtLeast = 'at_least';
    /** Stay within a [target, targetMax] band — "keep calories around 2,000–2,200". */
    case Maintain = 'maintain';

    public function label(): string
    {
        return match ($this) {
            self::AtMost   => 'at most',
            self::AtLeast  => 'at least',
            self::Maintain => 'around',
        };
    }

    /** Whether this direction needs an upper bound (targetMax) in addition to the target. */
    public function needsRange(): bool
    {
        return $this === self::Maintain;
    }

    /**
     * Is $value on the success side of the target(s) for this direction? For
     * Maintain, $upper is the top of the band (defaults to $target if omitted).
     */
    public function isMet(float $value, float $target, ?float $upper = null): bool
    {
        return match ($this) {
            self::AtMost   => $value <= $target,
            self::AtLeast  => $value >= $target,
            self::Maintain => $value >= $target && $value <= ($upper ?? $target),
        };
    }

    /** Translation key for UI rendering (i18n); label() stays for PHP contexts. */
    public function transKey(): string
    {
        return 'goal.direction.' . $this->value;
    }
}

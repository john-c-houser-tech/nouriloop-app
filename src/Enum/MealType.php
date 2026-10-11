<?php

declare(strict_types=1);

namespace App\Enum;

enum MealType: string
{
    case Breakfast = 'breakfast';
    case Lunch = 'lunch';
    case Dinner = 'dinner';
    case Snack = 'snack';

    public function label(): string
    {
        return match ($this) {
            self::Breakfast => 'Breakfast',
            self::Lunch => 'Lunch',
            self::Dinner => 'Dinner',
            self::Snack => 'Snack',
        };
    }

    /**
     * Translation key for UI rendering (`{{ type.transKey|trans }}` — i18n
     * Phase 1). label() stays for PHP/legacy contexts until those modules are
     * extracted; keep both in sync with the cases.
     */
    public function transKey(): string
    {
        return 'meal.type.' . $this->value;
    }

    /**
     * The likely meal for a local hour — preselects the log form's type so the
     * common case needs no dropdown interaction. Boundaries are deliberately
     * broad: 4–10 breakfast, 11–15 lunch, 16–21 dinner, late night/small hours
     * snack. The user can always change it.
     */
    public static function defaultForHour(int $hour): self
    {
        return match (true) {
            $hour >= 4 && $hour <= 10  => self::Breakfast,
            $hour >= 11 && $hour <= 15 => self::Lunch,
            $hour >= 16 && $hour <= 21 => self::Dinner,
            default                    => self::Snack,
        };
    }
}

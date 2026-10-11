<?php

declare(strict_types=1);

namespace App\Enum;

use App\Entity\NutritionData;

/**
 * A nutrient a goal can track. Each case knows how to pull its value out of a
 * meal's NutritionData and the unit it is reported in, so the scheduler and the
 * goal definition stay in sync with how meals are actually stored.
 */
enum GoalMetric: string
{
    case Calories = 'calories';
    case Protein = 'protein';
    case Carbohydrates = 'carbohydrates';
    case Fat = 'fat';
    case SaturatedFat = 'saturated_fat';
    case Fiber = 'fiber';
    case Sugar = 'sugar';
    case Sodium = 'sodium';

    public function label(): string
    {
        return match ($this) {
            self::Calories      => 'Calories',
            self::Protein       => 'Protein',
            self::Carbohydrates => 'Carbohydrates',
            self::Fat           => 'Fat',
            self::SaturatedFat  => 'Saturated fat',
            self::Fiber         => 'Fiber',
            self::Sugar         => 'Sugar',
            self::Sodium        => 'Sodium',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Calories => 'kcal',
            self::Sodium   => 'mg',
            default        => 'g',
        };
    }

    /**
     * Pulls this metric's value out of a meal's nutrition row (null if unknown).
     */
    public function valueFrom(NutritionData $n): ?float
    {
        return match ($this) {
            self::Calories      => $n->getCalories(),
            self::Protein       => $n->getProtein(),
            self::Carbohydrates => $n->getCarbohydrates(),
            self::Fat           => $n->getFat(),
            self::SaturatedFat  => $n->getSaturatedFat(),
            self::Fiber         => $n->getFiber(),
            self::Sugar         => $n->getSugar(),
            self::Sodium        => $n->getSodium(),
        };
    }

    /** The NutritionData field (camelCase nutrient key) this metric reads. */
    public function nutrientKey(): string
    {
        return lcfirst(str_replace('_', '', ucwords($this->value, '_')));
    }

    /** Translation key for UI rendering (i18n); label() stays for PHP contexts. */
    public function transKey(): string
    {
        return 'goal.metric.' . $this->value;
    }
}

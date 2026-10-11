<?php

declare(strict_types=1);

namespace App\Intake;

/**
 * The average daily intake over the meal days in a window: food averaged over
 * those days, plus the active supplements' daily amounts, kept apart like an
 * IntakeDay so a projection can change one without the other.
 */
final readonly class IntakeAverage
{
    /**
     * @param array<string, float> $food        every nutrient, averaged over the meal days
     * @param array<string, float> $supplements the active supplements' daily amounts
     * @param int                  $mealDays    how many days the average is over (at least 1)
     */
    public function __construct(
        public array $food,
        public array $supplements,
        public int $mealDays,
    ) {
    }

    /** @return array<string, float> food plus supplements, every nutrient */
    public function totals(): array
    {
        $totals = $this->food;
        foreach ($this->supplements as $nutrient => $amount) {
            $totals[$nutrient] = ($totals[$nutrient] ?? 0.0) + $amount;
        }

        return $totals;
    }
}

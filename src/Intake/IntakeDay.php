<?php

declare(strict_types=1);

namespace App\Intake;

/**
 * One local day's intake (see CONTEXT.md): what the day's meals added up to,
 * and what the active supplements added on top, kept apart so a screen can
 * show or score the two separately.
 */
final readonly class IntakeDay
{
    /**
     * $food is each nutrient summed over the day's meals (null when no meal
     * reported it); $supplements is the active supplements' daily amounts ([]
     * when they don't count that day).
     *
     * @param string                    $date local 'Y-m-d'
     * @param array<string, float|null> $food
     * @param array<string, float>      $supplements
     */
    public function __construct(
        public string $date,
        public array $food,
        public array $supplements,
    ) {
    }

    /** Food plus supplements for one nutrient (camelCase key); null when neither has it. */
    public function total(string $nutrient): ?float
    {
        $food = $this->food[$nutrient] ?? null;
        $supplement = $this->supplements[$nutrient] ?? null;

        return $food === null && $supplement === null ? null : ($food ?? 0.0) + ($supplement ?? 0.0);
    }
}

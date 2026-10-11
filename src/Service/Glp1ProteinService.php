<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Enum\HealthMetricType;
use App\Intake\DailyIntake;
use App\Repository\HealthMetricRepository;

/**
 * Computes the GLP-1 protein-focus summary: today's protein intake against a
 * daily target. The target is scaled to the user's most recent body weight
 * (lean-mass preservation matters on GLP-1 drugs, where appetite suppression
 * makes under-eating protein easy); with no weight on file it falls back to a
 * fixed high-protein default. Deterministic, no Claude call. Educational
 * wellness guidance, not medical advice.
 */
class Glp1ProteinService
{
    /** Grams of protein per kg of body weight (upper-normal intake for muscle retention). */
    public const PROTEIN_PER_KG = 1.4;

    /** Floor so a very low logged weight can't produce a trivially small target. */
    public const MIN_TARGET_G = 60.0;

    /** Target used when no body weight is on file (above the 50 g DV, per high-protein guidance). */
    public const DEFAULT_TARGET_G = 80.0;

    public function __construct(
        private readonly HealthMetricRepository $healthMetrics,
        private readonly DailyIntake $intake,
    ) {
    }

    /**
     * @param  iterable<MealEntry> $meals the user's meals for the day in question
     * @return array{target: float, consumed: float, remaining: float, percent: int, basedOnWeight: bool}
     */
    public function summary(User $user, iterable $meals): array
    {
        $weightKg = $this->healthMetrics->findLatestValue($user, HealthMetricType::Weight);
        $basedOnWeight = $weightKg !== null && $weightKg > 0;

        $target = $basedOnWeight
            ? max(round($weightKg * self::PROTEIN_PER_KG), self::MIN_TARGET_G)
            : self::DEFAULT_TARGET_G;

        $consumed = 0.0;
        foreach ($this->intake->forMeals($user, $meals) as $day) {
            $consumed += $day->total('protein') ?? 0.0;
        }
        $consumed = round($consumed, 1);

        return [
            'target'        => $target,
            'consumed'      => $consumed,
            'remaining'     => round(max($target - $consumed, 0.0), 1),
            'percent'       => (int) min(round($consumed / $target * 100), 100),
            'basedOnWeight' => $basedOnWeight,
        ];
    }
}

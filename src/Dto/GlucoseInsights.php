<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Cross-meal glucose insights for a user over a window: the meals that raised their
 * glucose most and least, those that diverged most from their nutritional estimate,
 * and headline averages. Immutable; produced by
 * {@see \App\Service\GlucoseInsightsService}.
 *
 * Educational wellness signal, not a medical measure.
 */
final class GlucoseInsights
{
    /**
     * @param int               $mealCount       analyzable meals (with a glucose curve) in the window
     * @param float|null        $avgDelta        mean peak rise above baseline, mg/dL
     * @param float|null        $avgIauc         mean incremental AUC, mg/dL·min
     * @param list<AnalyzedMeal> $topSpikers     biggest glucose rises, highest first
     * @param list<AnalyzedMeal> $gentlest       smallest glucose rises, lowest first
     * @param list<AnalyzedMeal> $aboveExpected  meals whose response most exceeded the nutritional estimate
     * @param bool              $aiDeepDiveEnabled coach available to this user (env flag AND per-user entitlement)
     * @param string|null       $coachingNarrative coach narrative; null when not entitled or generation failed
     */
    public function __construct(
        public readonly int $mealCount,
        public readonly ?float $avgDelta,
        public readonly ?float $avgIauc,
        public readonly array $topSpikers,
        public readonly array $gentlest,
        public readonly array $aboveExpected,
        public readonly bool $aiDeepDiveEnabled,
        public readonly ?string $coachingNarrative = null,
    ) {
    }

    public function hasData(): bool
    {
        return $this->mealCount > 0;
    }

    /** Immutable copy with the AI coach narrative attached (computed after the rankings). */
    public function withCoachingNarrative(?string $coachingNarrative): self
    {
        return new self(
            $this->mealCount,
            $this->avgDelta,
            $this->avgIauc,
            $this->topSpikers,
            $this->gentlest,
            $this->aboveExpected,
            $this->aiDeepDiveEnabled,
            $coachingNarrative,
        );
    }
}

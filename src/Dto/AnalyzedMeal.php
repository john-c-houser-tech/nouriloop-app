<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\MealEntry;

/**
 * A single meal bundled with its computed glucose response and (when nutrition data
 * is available) its measured-vs-expected glycemic impact. The unit of ranking in
 * {@see \App\Service\GlucoseInsightsService} and the row shape rendered on the
 * insights page and dashboard.
 *
 * Immutable. The response always has a curve (the service filters out meals without
 * one before constructing this), so {@see PostprandialResponse::$delta} is non-null.
 */
final class AnalyzedMeal
{
    public function __construct(
        public readonly MealEntry $meal,
        public readonly PostprandialResponse $response,
        public readonly ?GlycemicImpact $impact,
    ) {
    }
}

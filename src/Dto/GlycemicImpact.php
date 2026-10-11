<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Comparison of a meal's measured postprandial glucose response against what the
 * meal's nutritional content would predict. Immutable; produced by
 * {@see \App\Service\GlycemicImpactEstimator}.
 *
 * The "expected" side is a deterministic proxy-GI model (no Claude call): available
 * carbs × a sugar-weighted GI estimate, damped by fat and protein, scaled to iAUC
 * units. The "measured" side comes from {@see PostprandialResponse::$iauc}.
 *
 * Educational wellness signal, not a medical measure.
 */
final class GlycemicImpact
{
    /**
     * @param float  $estimatedGL  estimated glycemic load from the meal's macros
     * @param string $glCategory   'low' (≤10) | 'medium' (≤20) | 'high' (>20)
     * @param float  $expectedIAUC model-predicted incremental AUC (mg/dL·min)
     * @param float  $measuredIAUC actual iAUC from the CGM window
     * @param float  $ratio        measuredIAUC / expectedIAUC (1.0 = as predicted)
     * @param string $band         'much_lower'|'lower'|'as_expected'|'higher'|'much_higher'
     */
    public function __construct(
        public readonly float $estimatedGL,
        public readonly string $glCategory,
        public readonly float $expectedIAUC,
        public readonly float $measuredIAUC,
        public readonly float $ratio,
        public readonly string $band,
    ) {
    }
}

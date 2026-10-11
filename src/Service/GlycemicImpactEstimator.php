<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GlycemicImpact;
use App\Dto\PostprandialResponse;
use App\Entity\NutritionData;

/**
 * Compares a meal's measured postprandial glucose response to what its nutritional
 * content would predict via an estimated glycemic load (eGL) model.
 *
 * No Claude call, no persistence — pure deterministic arithmetic over fields that
 * NutritionData already stores. The model is intentionally simple and documented so
 * its weights can be tuned as more CGM data accumulates.
 *
 * Model overview:
 *   1. Available carbs = carbs − fiber (net carbs, floored at 0).
 *   2. Sugar fraction drives a proxy GI: 55 (all starch) → 85 (all sugar).
 *   3. Fat and protein each apply a damping factor (gastric emptying / insulin).
 *   4. eGL = proxy_GI × available_carbs / 100 × fat_damp × protein_damp.
 *   5. Expected iAUC = eGL × IAUC_PER_GL (calibrated to ~55 mg/dL·min per GL unit,
 *      the midpoint of the 40–75 range reported in the glycemic-index literature).
 *   6. ratio = measured_iAUC / expected_iAUC; bands reflect deviation from 1.0.
 *
 * Returns null when the GL model cannot apply: no carb data or no measured iAUC,
 * or when available carbs are < 1 g (essentially a zero-carb meal).
 */
class GlycemicImpactEstimator
{
    /** Proxy GI baseline for starchy carbs (GI of white bread ≈ 70, mixed starch ≈ 55). */
    public const GI_BASE = 55.0;

    /** Extra GI points added at 100% sugar fraction (fructose/glucose/sucrose ≈ 65–100). */
    public const GI_SUGAR_PREMIUM = 30.0;

    /** Fractional reduction in eGL per gram of fat (blunts gastric emptying). */
    public const FAT_DAMPING_RATE = 0.006;

    /** Minimum fat damping multiplier (fat-rich meals still have some response). */
    public const FAT_DAMPING_FLOOR = 0.50;

    /** Fractional reduction in eGL per gram of protein. */
    public const PROTEIN_DAMPING_RATE = 0.004;

    /** Minimum protein damping multiplier. */
    public const PROTEIN_DAMPING_FLOOR = 0.75;

    /** mg/dL·min of iAUC per GL unit; midpoint of published 40–75 range. */
    public const IAUC_PER_GL = 55.0;

    /**
     * Returns null when carb data is missing, iAUC is unavailable, or available
     * carbs are below 1 g (the GL model doesn't apply to essentially zero-carb meals).
     */
    public function estimate(NutritionData $nutrition, PostprandialResponse $measured): ?GlycemicImpact
    {
        if ($nutrition->getCarbohydrates() === null || $measured->iauc === null) {
            return null;
        }

        $carbs    = $nutrition->getCarbohydrates();
        $fiber    = $nutrition->getFiber() ?? 0.0;
        $sugar    = $nutrition->getSugar() ?? 0.0;
        $fat      = $nutrition->getFat() ?? 0.0;
        $protein  = $nutrition->getProtein() ?? 0.0;

        $availableCarbs = max(0.0, $carbs - $fiber);
        if ($availableCarbs < 1.0) {
            return null;
        }

        $sugarFraction  = min(1.0, $sugar / $availableCarbs);
        $proxyGi        = self::GI_BASE + $sugarFraction * self::GI_SUGAR_PREMIUM;
        $fatDamping     = max(self::FAT_DAMPING_FLOOR, 1.0 - $fat * self::FAT_DAMPING_RATE);
        $proteinDamping = max(self::PROTEIN_DAMPING_FLOOR, 1.0 - $protein * self::PROTEIN_DAMPING_RATE);
        $egl            = $proxyGi * $availableCarbs / 100.0 * $fatDamping * $proteinDamping;

        $glCategory = match (true) {
            $egl <= 10.0 => 'low',
            $egl <= 20.0 => 'medium',
            default      => 'high',
        };

        $expectedIauc = max(1.0, $egl * self::IAUC_PER_GL);
        $ratio        = $measured->iauc / $expectedIauc;

        $band = match (true) {
            $ratio < 0.60 => 'much_lower',
            $ratio < 0.80 => 'lower',
            $ratio < 1.25 => 'as_expected',
            $ratio < 1.60 => 'higher',
            default       => 'much_higher',
        };

        return new GlycemicImpact(
            estimatedGL:  round($egl, 1),
            glCategory:   $glCategory,
            expectedIAUC: round($expectedIauc, 1),
            measuredIAUC: round($measured->iauc, 1),
            ratio:        round($ratio, 2),
            band:         $band,
        );
    }
}

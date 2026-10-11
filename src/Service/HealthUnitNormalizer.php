<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\HealthMetricType;
use App\Enum\UnitSystem;

/**
 * The one place an imported health value is converted to its canonical storage
 * unit (weight kg, glucose mg/dL, body fat %) — shared by every ingestion path
 * (web XML/CSV/JSON import, the ingestion API, the sync CLI via that API) so they
 * can't disagree. Until 2026-09-30 each importer carried its own copy and two of
 * them converted only lb, silently storing mmol/L glucose as mg/dL.
 *
 * The inverse, canonical → display, is UnitConverter; the factors are shared.
 */
final class HealthUnitNormalizer
{
    /**
     * Below this, an unlabelled glucose value can only be mmol/L: real mg/dL
     * readings sit at roughly 40–600, while mmol/L readings sit at roughly 2–33.
     * Also the threshold `app:fix-glucose-units` repairs stored rows by.
     */
    public const GLUCOSE_MMOL_CEILING = 35.0;

    /**
     * @param string          $unit         the source's unit label, any case; '' when the
     *                                      source gives none
     * @param UnitSystem|null $assumeSystem for sources with no unit at all (CSV):
     *                                      an unlabelled weight from an imperial
     *                                      user is taken as lb
     */
    public static function toCanonical(
        HealthMetricType $type,
        float $value,
        string $unit,
        ?UnitSystem $assumeSystem = null,
    ): float {
        $unit = strtolower(trim($unit));

        return match ($type) {
            HealthMetricType::Weight => self::isPounds($unit)
                || ($unit === '' && $assumeSystem === UnitSystem::Imperial)
                ? round($value / UnitConverter::LB_PER_KG, 2)
                : $value,
            // Apple's export.xml spells mmol/L as "mmol<180.1558800000541>/L" (the
            // molar mass inline), so match the prefix, not the exact string.
            HealthMetricType::BloodGlucose => str_starts_with($unit, 'mmol')
                || ($unit === '' && $value < self::GLUCOSE_MMOL_CEILING)
                ? round($value * UnitConverter::MGDL_PER_MMOLL, 2)
                : $value,
            // Apple Health (and apps built on HealthKit) report percentages as a 0–1
            // fraction; a human body-fat percentage is never ≤ 1.5, so anything that
            // small is a fraction.
            HealthMetricType::BodyFatPercentage => $value <= 1.5 ? round($value * 100, 2) : $value,
            default => $value,
        };
    }

    private static function isPounds(string $unit): bool
    {
        return in_array($unit, ['lb', 'lbs', 'pound', 'pounds'], true);
    }

    /**
     * The precision a stored value keeps: steps are whole, everything else two
     * decimals. Sources report anything from integers to 15 significant digits.
     */
    public static function round(HealthMetricType $type, float $value): float
    {
        return $type === HealthMetricType::Steps ? round($value) : round($value, 2);
    }
}

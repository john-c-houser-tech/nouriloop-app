<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\HealthMetricType;
use App\Enum\UnitSystem;

/**
 * Converts canonically-stored health metrics (weight in kg, blood glucose in
 * mg/dL) to a user's chosen display units. Only weight and glucose are
 * locale-dependent; every other metric returns its canonical unit/value unchanged.
 *
 * The inverse of HealthUnitNormalizer (import → canonical), which uses these
 * same factors, so the round-trip import→display is exact.
 */
class UnitConverter
{
    public const LB_PER_KG      = 2.20462;
    public const MGDL_PER_MMOLL = 18.0182;

    /** Display unit string for a metric under the given system. */
    public function displayUnit(HealthMetricType $type, UnitSystem $system): string
    {
        return match ($type) {
            HealthMetricType::Weight => $system === UnitSystem::Imperial ? 'lb' : 'kg',
            HealthMetricType::BloodGlucose => $system === UnitSystem::Imperial ? 'mg/dL' : 'mmol/L',
            default => $type->unit(),
        };
    }

    /** Convert a canonical value (kg / mg/dL) to the display unit for the system. */
    public function toDisplay(HealthMetricType $type, float $canonical, UnitSystem $system): float
    {
        return match ($type) {
            HealthMetricType::Weight => $system === UnitSystem::Imperial
                ? round($canonical * self::LB_PER_KG, 2)
                : $canonical,
            HealthMetricType::BloodGlucose => $system === UnitSystem::Imperial
                ? $canonical
                : round($canonical / self::MGDL_PER_MMOLL, 2),
            default => $canonical,
        };
    }

    /**
     * Convert an aggregate series' numeric fields (avg/min/max) to display units,
     * leaving bucket/count untouched. No-op for non-locale-dependent metrics.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function convertAggregateRows(HealthMetricType $type, array $rows, UnitSystem $system): array
    {
        if ($type !== HealthMetricType::Weight && $type !== HealthMetricType::BloodGlucose) {
            return $rows;
        }
        if ($system === UnitSystem::Imperial && $type === HealthMetricType::BloodGlucose) {
            return $rows; // mg/dL is already the imperial unit
        }
        if ($system === UnitSystem::Metric && $type === HealthMetricType::Weight) {
            return $rows; // kg is already the metric unit
        }

        return array_map(function (array $r) use ($type, $system): array {
            foreach (['avg', 'min', 'max'] as $k) {
                if (isset($r[$k]) && $r[$k] !== null) {
                    $r[$k] = $this->toDisplay($type, (float) $r[$k], $system);
                }
            }

            return $r;
        }, $rows);
    }
}

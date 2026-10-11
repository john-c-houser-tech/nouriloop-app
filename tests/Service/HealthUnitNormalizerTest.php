<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\HealthMetricType;
use App\Enum\UnitSystem;
use App\Service\HealthUnitNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared import-time conversion to canonical units (kg, mg/dL, %).
 */
final class HealthUnitNormalizerTest extends TestCase
{
    /** @return iterable<string, array{HealthMetricType, float, string, ?UnitSystem, float}> */
    public static function cases(): iterable
    {
        yield 'weight lb' => [HealthMetricType::Weight, 176.37, 'lb', null, 80.0];
        yield 'weight LBS any case' => [HealthMetricType::Weight, 176.37, 'LBS', null, 80.0];
        yield 'weight pounds' => [HealthMetricType::Weight, 176.37, 'pounds', null, 80.0];
        yield 'weight kg untouched' => [HealthMetricType::Weight, 80.0, 'kg', null, 80.0];
        yield 'unlabelled weight, imperial user' => [
            HealthMetricType::Weight, 176.37, '', UnitSystem::Imperial, 80.0,
        ];
        yield 'unlabelled weight, metric user' => [HealthMetricType::Weight, 80.0, '', UnitSystem::Metric, 80.0];
        yield 'unlabelled weight, no assumption' => [HealthMetricType::Weight, 176.37, '', null, 176.37];
        yield 'labelled kg beats imperial preference' => [
            HealthMetricType::Weight, 80.0, 'kg', UnitSystem::Imperial, 80.0,
        ];

        yield 'glucose mmol/L' => [HealthMetricType::BloodGlucose, 5.4, 'mmol/L', null, 97.3];
        yield 'glucose Apple export mmol string' => [
            HealthMetricType::BloodGlucose, 5.4, 'mmol<180.1558800000541>/L', null, 97.3,
        ];
        yield 'glucose mg/dL untouched' => [HealthMetricType::BloodGlucose, 110.0, 'mg/dL', null, 110.0];
        yield 'unlabelled glucose in mmol range' => [HealthMetricType::BloodGlucose, 5.4, '', null, 97.3];
        yield 'unlabelled glucose in mg/dL range' => [HealthMetricType::BloodGlucose, 110.0, '', null, 110.0];

        yield 'body fat fraction' => [HealthMetricType::BodyFatPercentage, 0.25, '%', null, 25.0];
        yield 'body fat percent untouched' => [HealthMetricType::BodyFatPercentage, 25.0, '%', null, 25.0];

        yield 'a lb label on another type is ignored' => [HealthMetricType::HeartRate, 60.0, 'lb', null, 60.0];
        yield 'steps untouched' => [HealthMetricType::Steps, 8000.0, 'count', UnitSystem::Imperial, 8000.0];
    }

    #[DataProvider('cases')]
    public function testToCanonical(
        HealthMetricType $type,
        float $value,
        string $unit,
        ?UnitSystem $assume,
        float $expected,
    ): void {
        self::assertEqualsWithDelta($expected, HealthUnitNormalizer::toCanonical($type, $value, $unit, $assume), 0.01);
    }
}

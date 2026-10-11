<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\HealthMetricType;
use App\Enum\UnitSystem;
use App\Service\UnitConverter;
use PHPUnit\Framework\TestCase;

final class UnitConverterTest extends TestCase
{
    private UnitConverter $units;

    protected function setUp(): void
    {
        $this->units = new UnitConverter();
    }

    public function testWeightConvertsToPoundsForImperial(): void
    {
        self::assertEqualsWithDelta(
            176.37,
            $this->units->toDisplay(HealthMetricType::Weight, 80.0, UnitSystem::Imperial),
            0.01,
        );
        self::assertSame('lb', $this->units->displayUnit(HealthMetricType::Weight, UnitSystem::Imperial));
    }

    public function testWeightStaysKilogramsForMetric(): void
    {
        self::assertSame(80.0, $this->units->toDisplay(HealthMetricType::Weight, 80.0, UnitSystem::Metric));
        self::assertSame('kg', $this->units->displayUnit(HealthMetricType::Weight, UnitSystem::Metric));
    }

    public function testGlucoseConvertsToMmolForMetric(): void
    {
        self::assertEqualsWithDelta(
            5.55,
            $this->units->toDisplay(HealthMetricType::BloodGlucose, 100.0, UnitSystem::Metric),
            0.01,
        );
        self::assertSame('mmol/L', $this->units->displayUnit(HealthMetricType::BloodGlucose, UnitSystem::Metric));
    }

    public function testGlucoseStaysMgdlForImperial(): void
    {
        self::assertSame(100.0, $this->units->toDisplay(HealthMetricType::BloodGlucose, 100.0, UnitSystem::Imperial));
        self::assertSame('mg/dL', $this->units->displayUnit(HealthMetricType::BloodGlucose, UnitSystem::Imperial));
    }

    public function testUniversalMetricsArePassthrough(): void
    {
        foreach ([UnitSystem::Metric, UnitSystem::Imperial] as $sys) {
            self::assertSame(72.0, $this->units->toDisplay(HealthMetricType::HeartRate, 72.0, $sys));
            self::assertSame('bpm', $this->units->displayUnit(HealthMetricType::HeartRate, $sys));
            self::assertSame(8000.0, $this->units->toDisplay(HealthMetricType::Steps, 8000.0, $sys));
        }
    }

    public function testConvertAggregateRowsConvertsAvgMinMax(): void
    {
        $rows = [['bucket' => '2026-06-15', 'avg' => 80.0, 'min' => 79.0, 'max' => 81.0, 'count' => 3]];

        $out = $this->units->convertAggregateRows(HealthMetricType::Weight, $rows, UnitSystem::Imperial);

        self::assertEqualsWithDelta(176.37, $out[0]['avg'], 0.01);
        self::assertEqualsWithDelta(174.16, $out[0]['min'], 0.01);
        self::assertEqualsWithDelta(178.57, $out[0]['max'], 0.01);
        self::assertSame(3, $out[0]['count']);
    }

    public function testConvertAggregateRowsIsNoOpWhenUnitMatches(): void
    {
        $rows = [['bucket' => '2026-06-15', 'avg' => 80.0, 'min' => 79.0, 'max' => 81.0, 'count' => 3]];

        // Weight in metric and glucose in imperial are already canonical → untouched.
        self::assertSame(
            $rows,
            $this->units->convertAggregateRows(HealthMetricType::Weight, $rows, UnitSystem::Metric),
        );
        self::assertSame(
            $rows,
            $this->units->convertAggregateRows(HealthMetricType::BloodGlucose, $rows, UnitSystem::Imperial),
        );
    }

    public function testDefaultForTimezone(): void
    {
        self::assertSame(UnitSystem::Imperial, UnitSystem::defaultForTimezone('America/Los_Angeles'));
        self::assertSame(UnitSystem::Imperial, UnitSystem::defaultForTimezone('America/New_York'));
        // Sub-zones must resolve too (the bug Copilot flagged in the hard-coded list).
        self::assertSame(UnitSystem::Imperial, UnitSystem::defaultForTimezone('America/Indiana/Knox'));
        self::assertSame(UnitSystem::Imperial, UnitSystem::defaultForTimezone('America/North_Dakota/Center'));
        self::assertSame(UnitSystem::Imperial, UnitSystem::defaultForTimezone('America/Juneau'));
        self::assertSame(UnitSystem::Metric, UnitSystem::defaultForTimezone('Europe/London'));
        self::assertSame(UnitSystem::Metric, UnitSystem::defaultForTimezone('America/Sao_Paulo'));
        self::assertSame(UnitSystem::Metric, UnitSystem::defaultForTimezone('America/Toronto'));
    }
}

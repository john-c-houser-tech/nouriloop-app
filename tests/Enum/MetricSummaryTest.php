<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\HealthMetricType;
use App\Enum\MetricSummary;
use PHPUnit\Framework\TestCase;

final class MetricSummaryTest extends TestCase
{
    public function testStepsAreADailyTotal(): void
    {
        $summary = HealthMetricType::Steps->summary();

        self::assertSame(MetricSummary::Total, $summary);
        self::assertSame('SUM', $summary->valueAggregate());
        self::assertTrue($summary->collapsesRange()); // a total has no range band
        self::assertSame('daily totals', $summary->bucketLabel('day'));
        self::assertSame('hourly totals', $summary->bucketLabel('hour'));
    }

    public function testAveragedMetricsKeepTheirRange(): void
    {
        $averaged = [
            HealthMetricType::BloodGlucose,
            HealthMetricType::HeartRate,
            HealthMetricType::BloodPressureSystolic,
            HealthMetricType::BloodPressureDiastolic,
        ];

        foreach ($averaged as $type) {
            $summary = $type->summary();
            self::assertSame(MetricSummary::Average, $summary, $type->value);
            self::assertSame('AVG', $summary->valueAggregate());
            self::assertFalse($summary->collapsesRange());
        }

        self::assertSame('daily averages, range shaded', MetricSummary::Average->bucketLabel('day'));
    }

    public function testWeightAndBodyFatAreReadings(): void
    {
        foreach ([HealthMetricType::Weight, HealthMetricType::BodyFatPercentage] as $type) {
            $summary = $type->summary();
            self::assertSame(MetricSummary::Reading, $summary, $type->value);
            self::assertSame('AVG', $summary->valueAggregate());
            self::assertFalse($summary->collapsesRange());
        }

        self::assertSame('daily readings', MetricSummary::Reading->bucketLabel('day'));
    }
}

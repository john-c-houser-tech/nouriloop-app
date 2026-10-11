<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\GoalDefinition;
use App\Enum\GoalAggregation;
use App\Enum\GoalDirection;
use App\Enum\GoalMetric;
use PHPUnit\Framework\TestCase;

final class GoalDefinitionTest extends TestCase
{
    public function testRoundTripsThroughArray(): void
    {
        $def = new GoalDefinition(
            GoalMetric::Sugar,
            GoalDirection::AtMost,
            GoalAggregation::DailyAverage,
            30.0,
            7,
            45.0,
            new \DateTimeImmutable('2026-08-01T00:00:00+00:00'),
        );

        $restored = GoalDefinition::fromArray($def->toArray());

        self::assertSame(GoalMetric::Sugar, $restored->metric);
        self::assertSame(GoalDirection::AtMost, $restored->direction);
        self::assertSame(GoalAggregation::DailyAverage, $restored->aggregation);
        self::assertSame(30.0, $restored->targetValue);
        self::assertSame(7, $restored->evaluationWindowDays);
        self::assertSame(45.0, $restored->baselineValue);
        self::assertSame('2026-08-01', $restored->timeframeEnd?->format('Y-m-d'));
    }

    public function testNullableFieldsSurviveRoundTrip(): void
    {
        $def = new GoalDefinition(
            GoalMetric::Fiber,
            GoalDirection::AtLeast,
            GoalAggregation::DailyAverage,
            30.0,
            14,
        );

        $restored = GoalDefinition::fromArray($def->toArray());

        self::assertNull($restored->baselineValue);
        self::assertNull($restored->timeframeEnd);
    }

    public function testSummaryReadsAsASentence(): void
    {
        $def = new GoalDefinition(
            GoalMetric::Sugar,
            GoalDirection::AtMost,
            GoalAggregation::DailyAverage,
            30.0,
            7,
        );

        self::assertSame('daily average sugar at most 30 g', $def->summary());
        self::assertSame('g', $def->unit());
    }

    public function testMaintainRangeRoundTripsAndSummarises(): void
    {
        $def = new GoalDefinition(
            GoalMetric::Calories,
            GoalDirection::Maintain,
            GoalAggregation::DailyAverage,
            2000.0,
            7,
            targetMax: 2200.0,
        );

        $restored = GoalDefinition::fromArray($def->toArray());

        self::assertSame(2200.0, $restored->targetMax);
        self::assertSame('daily average calories around 2,000–2,200 kcal', $restored->summary());
    }

    public function testIsMetForEachDirection(): void
    {
        $atMost = new GoalDefinition(GoalMetric::Sugar, GoalDirection::AtMost, GoalAggregation::DailyAverage, 30.0, 7);
        self::assertTrue($atMost->isMet(25.0));
        self::assertFalse($atMost->isMet(35.0));

        $atLeast = new GoalDefinition(GoalMetric::Fiber, GoalDirection::AtLeast, GoalAggregation::DailyAverage, 28, 7);
        self::assertTrue($atLeast->isMet(30.0));
        self::assertFalse($atLeast->isMet(20.0));

        $maintain = new GoalDefinition(
            GoalMetric::Calories,
            GoalDirection::Maintain,
            GoalAggregation::DailyAverage,
            2000.0,
            7,
            targetMax: 2200.0,
        );
        self::assertTrue($maintain->isMet(2100.0));
        self::assertFalse($maintain->isMet(1900.0));
        self::assertFalse($maintain->isMet(2300.0));
    }
}

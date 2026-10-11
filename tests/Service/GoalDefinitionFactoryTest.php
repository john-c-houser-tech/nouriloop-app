<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\GoalAggregation;
use App\Enum\GoalDirection;
use App\Enum\GoalMetric;
use App\Service\GoalDefinitionFactory;
use PHPUnit\Framework\TestCase;

final class GoalDefinitionFactoryTest extends TestCase
{
    public function testBuildsFromAFullProposal(): void
    {
        $def = GoalDefinitionFactory::fromArray([
            'metric'               => 'fiber',
            'direction'            => 'at_least',
            'aggregation'          => 'daily_average',
            'targetValue'          => 28,
            'evaluationWindowDays' => 14,
        ]);

        self::assertSame(GoalMetric::Fiber, $def->metric);
        self::assertSame(GoalDirection::AtLeast, $def->direction);
        self::assertSame(GoalAggregation::DailyAverage, $def->aggregation);
        self::assertSame(28.0, $def->targetValue);
        self::assertSame(14, $def->evaluationWindowDays);
        self::assertNull($def->targetMax);
    }

    public function testMaintainKeepsTargetMaxOnlyWhenNumeric(): void
    {
        $def = GoalDefinitionFactory::fromArray([
            'metric'      => 'calories',
            'direction'   => 'maintain',
            'targetValue' => 2000,
            'targetMax'   => 2200,
        ]);
        self::assertSame(2200.0, $def->targetMax);

        // A maintain goal with a non-numeric/absent upper bound drops it (validator warns).
        $noMax = GoalDefinitionFactory::fromArray([
            'direction'   => 'maintain',
            'targetValue' => 2000,
            'targetMax'   => null,
        ]);
        self::assertNull($noMax->targetMax);

        // targetMax is ignored entirely for non-maintain directions.
        $atMost = GoalDefinitionFactory::fromArray([
            'direction'   => 'at_most',
            'targetValue' => 30,
            'targetMax'   => 50,
        ]);
        self::assertNull($atMost->targetMax);
    }

    public function testUnknownOrMissingValuesFallBackToDefaults(): void
    {
        $def = GoalDefinitionFactory::fromArray([
            'metric'    => 'not_a_metric',
            'direction' => 99,            // wrong type — must not throw
        ]);

        self::assertSame(GoalMetric::Sugar, $def->metric);
        self::assertSame(GoalDirection::AtMost, $def->direction);
        self::assertSame(GoalAggregation::DailyAverage, $def->aggregation);
        self::assertSame(0.0, $def->targetValue);
        self::assertSame(7, $def->evaluationWindowDays);
    }
}

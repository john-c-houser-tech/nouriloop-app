<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\GoalMetric;
use App\Intake\DailyIntake;
use App\Repository\NutritionDataRepository;
use App\Repository\SupplementRepository;
use App\Service\GoalMetricCalculator;
use App\Service\UserTime;
use App\Tests\Intake\StubsSupplements;
use PHPUnit\Framework\TestCase;

/**
 * The baseline trailing window must be built in the USER's local frame (meal
 * loggedAt is user-local wall-clock — see Timezones in CLAUDE.md). It was
 * server-frame until 2026-07-21 (Copilot catch on PR #165): a user ahead of the
 * server near midnight had baselines that ignored today's just-logged meals.
 */
final class GoalMetricCalculatorTest extends TestCase
{
    use StubsSupplements;

    public function testBaselineWindowBoundariesAreUserLocal(): void
    {
        $captured = null;
        $nutrition = $this->createStub(NutritionDataRepository::class);
        $nutrition->method('dailyNutrientTotals')
            ->willReturnCallback(function (User $u, \DateTimeImmutable $from, \DateTimeImmutable $to) use (&$captured) {
                $captured = [$from, $to];

                return [];
            });

        $userTime = new UserTime();
        $user = (new User())->setTimezone('Pacific/Kiritimati'); // UTC+14, far ahead of any server frame

        $intake = new DailyIntake($nutrition, $this->createStub(SupplementRepository::class), new UserTime());
        (new GoalMetricCalculator($intake, $userTime))->baseline($user, GoalMetric::Fiber, 30);

        self::assertNotNull($captured);
        [$from, $to] = $captured;
        self::assertSame(
            $userTime->startOfDaysAgo($user, 30)->format('Y-m-d H:i:s'),
            $from->format('Y-m-d H:i:s'),
            'window start is the user-local day boundary',
        );
        self::assertSame(
            $userTime->startOfTomorrow($user)->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
            'window end is the user-local start of tomorrow',
        );
    }

    public function testAProteinShakeCountsTowardTheGoalOnDaysWithMeals(): void
    {
        // ADR 0002: until 2026-10-06 goals ignored supplements, so a 25 g shake
        // never reached a protein goal that Trends showed it reaching.
        $nutrition = $this->createStub(NutritionDataRepository::class);
        $nutrition->method('dailyNutrientTotals')->willReturn([
            '2026-06-10' => ['protein' => 40.0],
            '2026-06-12' => ['protein' => 50.0],
        ]);
        $supplements = $this->createStub(SupplementRepository::class);
        $supplements->method('findActive')->willReturn(self::takenSince(['protein' => 25.0]));

        $intake = new DailyIntake($nutrition, $supplements, new UserTime());
        $calculator = new GoalMetricCalculator($intake, new UserTime());
        $daily = $calculator->dailyValues(
            new User(),
            GoalMetric::Protein,
            new \DateTimeImmutable('2026-06-10'),
            new \DateTimeImmutable('2026-06-13'),
        );

        self::assertSame(['2026-06-10' => 65.0, '2026-06-12' => 75.0], $daily, 'nothing on the 11th, no meals');
    }

    public function testAShakeStartedMidWindowCountsOnlyFromThatDay(): void
    {
        // Adding a supplement today must not lift last week's check-in window.
        $nutrition = $this->createStub(NutritionDataRepository::class);
        $nutrition->method('dailyNutrientTotals')->willReturn([
            '2026-06-10' => ['protein' => 40.0],
            '2026-06-12' => ['protein' => 50.0],
        ]);
        $supplements = $this->createStub(SupplementRepository::class);
        $supplements->method('findActive')->willReturn(self::takenSince(['protein' => 25.0], '2026-06-12'));

        $intake = new DailyIntake($nutrition, $supplements, new UserTime());
        $calculator = new GoalMetricCalculator($intake, new UserTime());
        $daily = $calculator->dailyValues(
            new User(),
            GoalMetric::Protein,
            new \DateTimeImmutable('2026-06-10'),
            new \DateTimeImmutable('2026-06-13'),
        );

        self::assertSame(['2026-06-10' => 40.0, '2026-06-12' => 75.0], $daily);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalFrequency;
use App\Service\GoalScheduler;
use PHPUnit\Framework\TestCase;

final class GoalSchedulerTest extends TestCase
{
    private GoalScheduler $scheduler;

    protected function setUp(): void
    {
        $this->scheduler = new GoalScheduler();
    }

    private function goal(
        GoalFrequency $freq,
        int $hour,
        int $minute,
        ?int $dow = null,
        string $tz = 'UTC',
    ): NutritionGoal {
        return (new NutritionGoal())
            ->setUser((new User())->setTimezone($tz))
            ->setFrequency($freq)
            ->setHour($hour)
            ->setMinute($minute)
            ->setDayOfWeek($dow);
    }

    // The hour/minute are interpreted in the goal owner's tz; the goals above use UTC,
    // and building $after in UTC keeps the wall-clock math deterministic on any machine.
    private function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
    }

    public function testDailyLaterTodayWhenTimeNotYetPassed(): void
    {
        $next = $this->scheduler->computeNextRun(
            $this->goal(GoalFrequency::Daily, 18, 0),
            $this->at('2026-06-11 10:00:00'),
        );

        self::assertSame('2026-06-11 18:00', $next->format('Y-m-d H:i'));
    }

    public function testDailyRollsToTomorrowWhenTimePassed(): void
    {
        $next = $this->scheduler->computeNextRun(
            $this->goal(GoalFrequency::Daily, 18, 0),
            $this->at('2026-06-11 20:00:00'),
        );

        self::assertSame('2026-06-12 18:00', $next->format('Y-m-d H:i'));
    }

    public function testWeeklyLandsOnTargetDayWithinAWeek(): void
    {
        $goal = $this->goal(GoalFrequency::Weekly, 9, 0, 7); // Sunday 09:00

        $after = $this->at('2026-06-08 12:00:00');
        $next = $this->scheduler->computeNextRun($goal, $after);

        self::assertSame('7', $next->format('N'));            // a Sunday
        self::assertSame('09:00', $next->format('H:i'));
        self::assertGreaterThan($after, $next);
        self::assertLessThanOrEqual($after->modify('+7 days'), $next);
    }

    public function testWeeklyDefaultsToSundayWhenDayOfWeekMissing(): void
    {
        // No day-of-week set on a weekly goal → defaults to Sunday (7), not Monday.
        $next = $this->scheduler->computeNextRun(
            $this->goal(GoalFrequency::Weekly, 9, 0, null),
            $this->at('2026-06-08 12:00:00'),
        );

        self::assertSame('7', $next->format('N'));
    }

    public function testWeeklySameDayBeforeTimeSchedulesToday(): void
    {
        // 2026-06-14 is a Sunday; the run is at 18:00 and "now" is that morning.
        $next = $this->scheduler->computeNextRun(
            $this->goal(GoalFrequency::Weekly, 18, 0, 7),
            $this->at('2026-06-14 08:00:00'),
        );

        self::assertSame('2026-06-14 18:00', $next->format('Y-m-d H:i'));
    }

    public function testWeeklySameDayAfterTimeRollsAWeek(): void
    {
        // Sunday evening, past the run time → next week.
        $next = $this->scheduler->computeNextRun(
            $this->goal(GoalFrequency::Weekly, 18, 0, 7),
            $this->at('2026-06-14 20:00:00'),
        );

        self::assertSame('2026-06-21 18:00', $next->format('Y-m-d H:i'));
    }

    public function testHourIsInterpretedInTheUserZoneAndReturnedInTheCallerFrame(): void
    {
        // Daily 20:00 for a New York user; "now" is 2026-06-11 10:00 UTC (06:00 EDT),
        // so the next run is today 20:00 EDT == 2026-06-12 00:00 UTC.
        $next = $this->scheduler->computeNextRun(
            $this->goal(GoalFrequency::Daily, 20, 0, null, 'America/New_York'),
            $this->at('2026-06-11 10:00:00'),
        );

        // Returned in the caller's (UTC) frame for storage/comparison...
        self::assertSame('UTC', $next->getTimezone()->getName());
        self::assertSame('2026-06-12 00:00', $next->format('Y-m-d H:i'));
        // ...but it is 20:00 in the user's own zone.
        self::assertSame('20:00', $next->setTimezone(new \DateTimeZone('America/New_York'))->format('H:i'));
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalFrequency;

/**
 * Computes a goal's next scheduled check-in time from its structured schedule
 * fields. The hour/minute/dayOfWeek are interpreted in the goal owner's timezone
 * (so "20:00 weekly on Sunday" means the user's local 8 PM), while the returned
 * instant is expressed in the caller's frame — the cron passes
 * `new \DateTimeImmutable()` and `NutritionGoalRepository::findDue()` compares the
 * persisted nextRunAt against the same `new \DateTimeImmutable()`, so both sides
 * share one absolute timeline regardless of the user's zone.
 *
 * Pure and deterministic (the reference instant is injected), so it's unit-testable
 * without a clock.
 */
class GoalScheduler
{
    /**
     * The first run strictly after $after that matches the goal's schedule.
     */
    public function computeNextRun(NutritionGoal $goal, \DateTimeImmutable $after): \DateTimeImmutable
    {
        $userZone = new \DateTimeZone($goal->getUser()?->getTimezone() ?? User::DEFAULT_TIMEZONE);
        $storeZone = $after->getTimezone();

        // Roll forward in the user's local wall-clock so the hour/minute/day mean
        // their local time (and DST is handled by the named zone).
        $after = $after->setTimezone($userZone);
        $candidate = $after->setTime($goal->getHour(), $goal->getMinute());

        if ($goal->getFrequency() === GoalFrequency::Weekly) {
            $targetDow = $goal->getDayOfWeek() ?? 7;          // ISO-8601: 1=Mon … 7=Sun (default Sunday)
            $candidateDow = (int) $candidate->format('N');
            $delta = (($targetDow - $candidateDow) % 7 + 7) % 7;
            $candidate = $candidate->modify("+{$delta} days");
        }

        // Roll forward until strictly after $after (covers "today but already past"
        // for daily, and the same-day case for weekly).
        if ($candidate <= $after) {
            $step = $goal->getFrequency() === GoalFrequency::Weekly ? '+7 days' : '+1 day';
            $candidate = $candidate->modify($step);
        }

        // Persist as an absolute instant in the caller's frame (see class docblock).
        return $candidate->setTimezone($storeZone);
    }
}

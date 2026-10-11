<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\NutritionGoalRepository;

/**
 * The one place a user's timezone gets changed. Beyond setting the column, it
 * recomputes the materialized `nextRunAt` of the user's active goals:
 * `NutritionGoalRepository::findDue()` schedules purely off that persisted
 * instant, and it is only recomputed after a run fires — so without this, a
 * user repairing LA→New York would still get their next check-in three hours
 * late, in the old zone's frame (Copilot catch on PR #163). Callers own the
 * flush. Used by Settings ▸ Timezone, the mismatch banner's quick-set route,
 * and the onboarding notifications step.
 */
class TimezoneChanger
{
    public function __construct(
        private readonly GoalScheduler $scheduler,
        private readonly NutritionGoalRepository $goals,
    ) {
    }

    /**
     * Applies a timezone change; a no-op (goals untouched) when the value is
     * unchanged. The caller has already validated $timezone against the IANA
     * whitelist.
     */
    public function change(User $user, string $timezone): void
    {
        if ($timezone === $user->getTimezone()) {
            return;
        }

        $user->setTimezone($timezone);
        $this->rescheduleActiveGoals($user);
    }

    /**
     * Recomputes active goals' next check-in instants in the user's (new)
     * timezone. Public for the Settings form path, where the form itself has
     * already mutated the User by the time the change is detectable.
     */
    public function rescheduleActiveGoals(User $user): void
    {
        foreach ($this->goals->findByUser($user) as $goal) {
            if ($goal->isActive() && $goal->getNextRunAt() !== null) {
                $goal->setNextRunAt($this->scheduler->computeNextRun($goal, new \DateTimeImmutable()));
            }
        }
    }
}

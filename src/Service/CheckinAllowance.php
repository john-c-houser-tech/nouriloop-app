<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\GoalCheckinRepository;

/**
 * The Free tier's lifetime goal check-in allowance. Goal *definition* is free for
 * everyone; a Free account's first FREE_ALLOWANCE *evaluated* check-ins (Achieved /
 * On-track / Off-track — no-data and error runs don't count, they make no Claude
 * call) are included, after which scheduled evaluation pauses until an upgrade.
 *
 * The count is derived from persisted GoalCheckin rows (no column, no migration) and
 * is per *account*, not per goal — otherwise N goals would multiply Claude spend.
 * Entitlement resolves through SubscriptionService, so comps and the grace period
 * apply (a beta-comp Loop+ user is unlimited until their comp lapses).
 *
 * ⚠️ Known, accepted tradeoff of the derived count (plan §2/§8, flagged again by
 * Copilot on PR #166): check-ins cascade-delete with their goal, so deleting every
 * goal (or "Start fresh") resets the count. Deliberate — the "exploit" costs the
 * user their entire goal history each cycle for at most ~6 narratives of Claude
 * spend (pennies), and a real column would need a migration + backfill the plan
 * explicitly declined. Revisit only if abuse actually appears (the same posture as
 * registration pre-hijacking — narrowed, not eliminated).
 */
class CheckinAllowance
{
    /**
     * ≈ six weeks of one weekly goal: long enough to build the logging habit and
     * watch a trend move before the "that was your last included check-in" moment.
     */
    public const FREE_ALLOWANCE = 6;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly GoalCheckinRepository $checkins,
    ) {
    }

    /** Whether the user's tier has no allowance cap (Loop and up). */
    public function isUnlimited(User $user): bool
    {
        return $this->subscriptions->hasFeature($user, 'unlimited_checkins');
    }

    /** Evaluated check-ins consumed so far, across all the user's goals. */
    public function used(User $user): int
    {
        return $this->checkins->countEvaluatedForUser($user);
    }

    /** Remaining included check-ins, or null when the user is unlimited. */
    public function remaining(User $user): ?int
    {
        if ($this->isUnlimited($user)) {
            return null;
        }

        return max(0, self::FREE_ALLOWANCE - $this->used($user));
    }

    /** True when a capped user has consumed their whole allowance. */
    public function isExhausted(User $user): bool
    {
        return $this->remaining($user) === 0;
    }
}

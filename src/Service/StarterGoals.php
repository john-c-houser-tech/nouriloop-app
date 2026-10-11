<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GoalDefinition;
use App\Entity\User;
use App\Enum\GoalAggregation;
use App\Enum\GoalDirection;
use App\Enum\GoalMetric;

/**
 * The one-click starter goals offered in onboarding step 2 (and atop the goal chat
 * for users with no goals yet), so a brand-new user's second-ever action doesn't
 * have to be an open-ended AI conversation or a ten-field expert form.
 *
 * Targets start from the DietaryReference daily values and are nudged by the
 * user's own trailing 30-day average where one exists (a brand-new user has none,
 * so they simply get the DV). Every produced definition is safe by construction —
 * clamps keep it clear of every GoalSafetyValidator warning (pinned by
 * StarterGoalsTest), so the one-click path never needs an acknowledge step.
 */
class StarterGoals
{
    /** Template keys, in display order. */
    public const TEMPLATES = ['fiber', 'protein', 'sugar', 'calories'];

    /** History window the personalization looks back over. */
    private const BASELINE_DAYS = 30;

    /** Each check-in evaluates the trailing week. */
    private const WINDOW_DAYS = 7;

    /** Calorie fallback when the user has no history (2,000 kcal DV basis). */
    private const CALORIE_DEFAULT = 2000.0;

    /** Clamp for a personalized calorie ceiling — comfortably above the 1,200 safety floor. */
    private const CALORIE_MIN = 1500.0;
    private const CALORIE_MAX = 3500.0;

    /** Floor for a personalized sugar target (half the 50 g DV). */
    private const SUGAR_MIN = 25.0;

    /**
     * Ceiling for a personalized at-least target, as a multiple of the DV
     * (fiber ≤ 56 g, protein ≤ 100 g). A garbage or sparse AI-derived baseline
     * must never one-click an absurd "eat 11,000 g of protein" goal; past this
     * cap a DV-anchored starter isn't the right tool anyway, and a trivially
     * met goal (editable afterward) beats an unsafe one.
     */
    private const AT_LEAST_MAX_DV_MULTIPLE = 2.0;

    public function __construct(
        private readonly DietaryReference $reference,
        private readonly GoalMetricCalculator $calculator,
    ) {
    }

    public function isTemplate(string $key): bool
    {
        return in_array($key, self::TEMPLATES, true);
    }

    /**
     * Builds the definition for a starter template, personalized to the user's
     * recent history. Baseline capture is left to GoalFactory (which snapshots it
     * over the goal's own window, like every other creation path).
     */
    public function definitionFor(User $user, string $template): GoalDefinition
    {
        if (!$this->isTemplate($template)) {
            throw new \InvalidArgumentException(sprintf('Unknown starter goal template "%s".', $template));
        }

        $metric = match ($template) {
            'fiber'    => GoalMetric::Fiber,
            'protein'  => GoalMetric::Protein,
            'sugar'    => GoalMetric::Sugar,
            'calories' => GoalMetric::Calories,
        };
        $direction = match ($template) {
            'fiber', 'protein'  => GoalDirection::AtLeast,
            'sugar', 'calories' => GoalDirection::AtMost,
        };

        $baseline = $this->calculator->baseline($user, $metric, self::BASELINE_DAYS);

        return new GoalDefinition(
            $metric,
            $direction,
            GoalAggregation::DailyAverage,
            $this->target($metric, $direction, $baseline),
            self::WINDOW_DAYS,
        );
    }

    /**
     * The starting target: the DietaryReference DV, nudged so the goal is a real
     * (but gentle) change from where the user already is rather than trivially
     * met or wildly out of reach.
     */
    private function target(GoalMetric $metric, GoalDirection $direction, ?float $baseline): float
    {
        if ($metric === GoalMetric::Calories) {
            // No one-size DV for calories: aim slightly under the user's own
            // average (rounded to 50), clamped well clear of the safety floor.
            if ($baseline === null) {
                return self::CALORIE_DEFAULT;
            }

            $target = round($baseline * 0.95 / 50) * 50;

            return max(self::CALORIE_MIN, min(self::CALORIE_MAX, $target));
        }

        $dv = (float) $this->reference->for($metric)['value'];

        if ($direction === GoalDirection::AtLeast) {
            // Already at/above the DV → aim ~10% above their own average,
            // capped so a bad baseline can't produce an absurd target.
            if ($baseline !== null && $baseline >= $dv) {
                return min(round($baseline * 1.1), $dv * self::AT_LEAST_MAX_DV_MULTIPLE);
            }

            return $dv;
        }

        // At-most (sugar): already under the DV → tighten to ~90% of their own
        // average, floored so a sparse day-1 history can't produce a silly target.
        if ($baseline !== null && $baseline < $dv) {
            return max(self::SUGAR_MIN, round($baseline * 0.9));
        }

        return $dv;
    }
}

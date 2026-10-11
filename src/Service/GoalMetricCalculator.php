<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\GoalAggregation;
use App\Enum\GoalMetric;
use App\Intake\DailyIntake;

/**
 * Computes a goal metric's value from a user's logged meals — per local day, and
 * aggregated over a window. Used to anchor a new goal to the user's current
 * baseline (so targets are framed relative to where they actually are) and, in the
 * scheduled evaluation, to measure progress. The arithmetic lives here, not in
 * Claude, so the numbers are deterministic.
 */
class GoalMetricCalculator
{
    public function __construct(
        private readonly DailyIntake $intake,
        private readonly UserTime $userTime,
    ) {
    }

    /**
     * Per-local-day totals of the metric across the half-open [from, to), keyed 'Y-m-d'. Only
     * days that have at least one logged value appear.
     *
     * @return array<string, float>
     */
    public function dailyValues(User $user, GoalMetric $metric, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $daily = [];
        foreach ($this->intake->days($user, $from, $to) as $date => $day) {
            $value = $day->total($metric->nutrientKey());
            if ($value !== null) {
                $daily[$date] = $value;
            }
        }

        return $daily;
    }

    /**
     * Rolls a per-day series up into a single figure per the aggregation. Returns
     * null when there are no days with data (the empty-window case).
     *
     * @param array<string, float> $daily
     */
    public function aggregate(GoalAggregation $aggregation, array $daily): ?float
    {
        if ($daily === []) {
            return null;
        }

        $sum = array_sum($daily);

        return match ($aggregation) {
            GoalAggregation::DailyAverage => $sum / count($daily),
            GoalAggregation::WindowTotal  => $sum,
        };
    }

    /**
     * The user's current standing for a metric over the trailing $days, rolled up
     * with the given aggregation so it's directly comparable to a goal's target
     * (a daily-average goal gets an average; a window-total goal gets a total). Null
     * when nothing is logged in that window.
     */
    public function baseline(
        User $user,
        GoalMetric $metric,
        int $days,
        GoalAggregation $aggregation = GoalAggregation::DailyAverage,
    ): ?float {
        [$from, $to] = $this->trailingWindow($user, $days);

        return $this->aggregate($aggregation, $this->dailyValues($user, $metric, $from, $to));
    }

    /**
     * Daily-average baseline for every metric over the trailing $days, keyed by
     * metric value — computed from a SINGLE intake query (the metrics all read
     * from the same days), for the form hints and conversation grounding.
     *
     * @return array<string, float|null>
     */
    public function baselineMap(User $user, int $days): array
    {
        [$from, $to] = $this->trailingWindow($user, $days);

        $perMetricDaily = [];
        foreach (GoalMetric::cases() as $metric) {
            $perMetricDaily[$metric->value] = [];
        }

        foreach ($this->intake->days($user, $from, $to) as $date => $day) {
            foreach (GoalMetric::cases() as $metric) {
                $value = $day->total($metric->nutrientKey());
                if ($value !== null) {
                    $perMetricDaily[$metric->value][$date] = $value;
                }
            }
        }

        $out = [];
        foreach (GoalMetric::cases() as $metric) {
            $out[$metric->value] = $this->aggregate(GoalAggregation::DailyAverage, $perMetricDaily[$metric->value]);
        }

        return $out;
    }

    /**
     * Boundaries in the user's local frame (meal loggedAt is user-local
     * wall-clock — see Timezones in CLAUDE.md). Was server-frame until
     * 2026-07-21: for users ahead of the server near midnight, baselines
     * could miss today's just-logged meal.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function trailingWindow(User $user, int $days): array
    {
        return [
            $this->userTime->startOfDaysAgo($user, $days),
            $this->userTime->startOfTomorrow($user),
        ];
    }
}

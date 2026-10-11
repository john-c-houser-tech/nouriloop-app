<?php

declare(strict_types=1);

namespace App\Intake;

use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\Supplement;
use App\Entity\User;
use App\Repository\NutritionDataRepository;
use App\Repository\SupplementRepository;
use App\Service\UserTime;

/**
 * Daily intake (see CONTEXT.md): what a user took in per local day, as every
 * screen that adds up nutrition reads it (Trends, the Dashboard, the
 * inflammation score, supplement recommendations, Predict, goals, the GLP-1
 * card, the digests). A day exists only if a meal with nutrition was logged on
 * it, and the active supplements count on every such day, never on their own
 * (docs/adr/0002-supplements-count-on-meal-days.md), and each only from the local
 * day it was added: a supplement started today doesn't rewrite last week.
 *
 * Not final so the services that read it can be unit-tested with a stub.
 */
class DailyIntake
{
    public function __construct(
        private readonly NutritionDataRepository $nutrition,
        private readonly SupplementRepository $supplements,
        private readonly UserTime $userTime,
    ) {
    }

    /**
     * One day per local date in the half-open [from, to) that has a meal with
     * nutrition, in date order.
     *
     * @return array<string, IntakeDay> keyed 'Y-m-d'
     */
    public function days(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $totals = $this->nutrition->dailyNutrientTotals($user, $from, $to);
        $stack = $this->stack($user, $totals !== []);

        $days = [];
        foreach ($totals as $date => $food) {
            $days[$date] = new IntakeDay($date, $food, $this->supplementsOn($stack, $date));
        }

        return $days;
    }

    /**
     * The same from meals the caller already holds (nutrition loaded), so a
     * page that has them doesn't query again.
     *
     * @param iterable<MealEntry> $meals
     *
     * @return array<string, IntakeDay> keyed 'Y-m-d'
     */
    public function forMeals(User $user, iterable $meals): array
    {
        $food = [];
        foreach ($meals as $meal) {
            $nutrition = $meal->getNutritionData();
            if ($nutrition === null) {
                continue;
            }
            $day = $meal->getLoggedAt()->format('Y-m-d');
            $food[$day] ??= array_fill_keys(NutritionData::FIELDS, null);
            foreach ($nutrition->toArray() as $field => $value) {
                if ($value !== null && array_key_exists($field, $food[$day])) {
                    $food[$day][$field] = ($food[$day][$field] ?? 0.0) + $value;
                }
            }
        }
        ksort($food);

        $stack = $this->stack($user, $food !== []);

        $days = [];
        foreach ($food as $day => $totals) {
            $days[$day] = new IntakeDay($day, $totals, $this->supplementsOn($stack, $day));
        }

        return $days;
    }

    /**
     * The average daily intake over the meal days in [from, to): each
     * nutrient's food total over those days divided by how many there are (days
     * without meals never drag it down), and likewise the supplements' (so one
     * started partway through counts for the days since). Null when there are
     * no meal days.
     */
    public function average(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): ?IntakeAverage
    {
        $totals = $this->nutrition->dailyNutrientTotals($user, $from, $to);
        if ($totals === []) {
            return null;
        }

        $stack = $this->stack($user, true);
        $food = array_fill_keys(NutritionData::FIELDS, 0.0);
        $supplements = [];
        foreach ($totals as $date => $day) {
            foreach ($day as $field => $value) {
                $food[$field] += $value ?? 0.0;
            }
            foreach ($this->supplementsOn($stack, $date) as $nutrient => $amount) {
                $supplements[$nutrient] = ($supplements[$nutrient] ?? 0.0) + $amount;
            }
        }
        $perDay = static fn (float $sum): float => $sum / count($totals);

        return new IntakeAverage(array_map($perDay, $food), array_map($perDay, $supplements), count($totals));
    }

    /**
     * The user's active supplements, each with the local day it was added.
     *
     * @return list<array{since: string, daily: array<string, float>}>
     */
    private function stack(User $user, bool $needed): array
    {
        $active = $needed ? $this->supplements->findActive($user) : [];
        if ($active === []) {
            return [];
        }
        $zone = $this->userTime->zone($user);

        return array_map(
            static fn (Supplement $s): array => [
                'since' => $s->getCreatedAt()->setTimezone($zone)->format('Y-m-d'),
                'daily' => $s->dailyNutrients(),
            ],
            $active,
        );
    }

    /**
     * What the supplements already started by $date add that day.
     *
     * @param list<array{since: string, daily: array<string, float>}> $stack
     *
     * @return array<string, float>
     */
    private function supplementsOn(array $stack, string $date): array
    {
        $totals = [];
        foreach ($stack as $supplement) {
            if ($supplement['since'] > $date) {
                continue;
            }
            foreach ($supplement['daily'] as $nutrient => $amount) {
                $totals[$nutrient] = ($totals[$nutrient] ?? 0.0) + $amount;
            }
        }

        return $totals;
    }
}

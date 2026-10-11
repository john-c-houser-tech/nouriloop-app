<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MealEntry;

/**
 * Surfaces which foods are associated with higher self-reported nausea — the
 * #1 GLP-1 side effect. For each ingredient, compares the user's average nausea
 * level on days they ate it vs days they didn't; foods with a positive "lift"
 * are flagged as possible triggers, ranked by that lift.
 *
 * Pure arithmetic over already-fetched data (no persistence, recomputed at view
 * time), like DietSymptomCorrelationService. Results are descriptive, not causal
 * — association on a small self-reported sample — and the UI hedges accordingly.
 */
class NauseaTriggerService
{
    /** Minimum days with a nausea reading before any analysis is meaningful. */
    public const MIN_OVERLAP_DAYS = 4;

    /** A food must be eaten on at least this many evaluated days to be ranked. */
    public const MIN_DAYS_PER_FOOD = 3;

    /** Cap on the number of triggers returned. */
    public const TOP_N = 8;

    /**
     * @param  iterable<MealEntry> $meals        meals in the window (with ingredients)
     * @param  array<string, int>  $nauseaByDate nausea level (0–10) keyed by 'Y-m-d'
     * @return array{
     *     evaluatedDays: int,
     *     insufficient: bool,
     *     overallAvg: float|null,
     *     triggers: list<array{
     *         food: string, daysEaten: int,
     *         avgWhenEaten: float|null, avgWhenNot: float|null, lift: float
     *     }>
     * }
     */
    public function analyze(iterable $meals, array $nauseaByDate): array
    {
        return $this->summarize($this->ingredientDaysFromMeals($meals), $nauseaByDate);
    }

    /**
     * @param  array<string, array<string, true>> $foodsByDate  'Y-m-d' => set of normalized food names eaten that day
     * @param  array<string, int>                  $nauseaByDate 'Y-m-d' => level (0–10)
     * @return array{
     *     evaluatedDays: int,
     *     insufficient: bool,
     *     overallAvg: float|null,
     *     triggers: list<array{
     *         food: string, daysEaten: int,
     *         avgWhenEaten: float|null, avgWhenNot: float|null, lift: float
     *     }>
     * }
     */
    public function summarize(array $foodsByDate, array $nauseaByDate): array
    {
        // Evaluated days are exactly the days carrying a nausea reading.
        $days = array_keys($nauseaByDate);
        $n = count($days);
        $overallAvg = $this->avg(array_values($nauseaByDate));

        if ($n < self::MIN_OVERLAP_DAYS) {
            return [
                'evaluatedDays' => $n,
                'insufficient'  => true,
                'overallAvg'    => $overallAvg,
                'triggers'      => [],
            ];
        }

        // Every distinct food seen on an evaluated day.
        $foods = [];
        foreach ($days as $date) {
            foreach (array_keys($foodsByDate[$date] ?? []) as $food) {
                $foods[$food] = true;
            }
        }

        $triggers = [];
        foreach (array_keys($foods) as $food) {
            $eaten = [];
            $notEaten = [];
            foreach ($days as $date) {
                if (isset($foodsByDate[$date][$food])) {
                    $eaten[] = $nauseaByDate[$date];
                } else {
                    $notEaten[] = $nauseaByDate[$date];
                }
            }

            // Need enough exposure and a contrast group to compare against.
            if (count($eaten) < self::MIN_DAYS_PER_FOOD || $notEaten === []) {
                continue;
            }

            $avgEaten = $this->avg($eaten);
            $avgNot   = $this->avg($notEaten);
            $lift     = round((float) $avgEaten - (float) $avgNot, 1);

            // Only foods linked to *more* nausea are triggers.
            if ($lift <= 0.0) {
                continue;
            }

            $triggers[] = [
                'food'         => $food,
                'daysEaten'    => count($eaten),
                'avgWhenEaten' => $avgEaten,
                'avgWhenNot'   => $avgNot,
                'lift'         => $lift,
            ];
        }

        usort($triggers, static fn (array $a, array $b): int => $b['lift'] <=> $a['lift']);

        return [
            'evaluatedDays' => $n,
            'insufficient'  => false,
            'overallAvg'    => $overallAvg,
            'triggers'      => array_slice($triggers, 0, self::TOP_N),
        ];
    }

    /**
     * Reduces meals to a per-day set of normalized ingredient names (deduped
     * within a day), the input the association math needs.
     *
     * @param  iterable<MealEntry> $meals
     * @return array<string, array<string, true>>
     */
    private function ingredientDaysFromMeals(iterable $meals): array
    {
        $out = [];
        foreach ($meals as $meal) {
            $date = $meal->getLoggedAt()->format('Y-m-d');
            foreach ($meal->getIngredients() as $ingredient) {
                $name = $this->normalize($ingredient['name'] ?? '');
                if ($name !== '') {
                    $out[$date][$name] = true;
                }
            }
        }

        return $out;
    }

    private function normalize(string $name): string
    {
        return trim(mb_strtolower($name));
    }

    /**
     * @param  list<int|float> $values
     */
    private function avg(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 1);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Intake\DailyIntake;
use App\Repository\MealEntryRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Estimates a daily dietary "inflammation score" from logged meals.
 *
 * This is a deterministic, transparent heuristic — NOT a medical measure. It
 * blends two signals the app already captures:
 *
 *   1. a nutrient backbone, whose directionality follows the Dietary
 *      Inflammatory Index (Shivappa et al.): saturated fat / sugar / sodium are
 *      pro-inflammatory; fiber and the antioxidant micronutrients (vitamins
 *      A/C/D/E, B6, folate, B12, magnesium, zinc, selenium) are anti-inflammatory;
 *   2. curated food-category adjustments matched against ingredient names, so
 *      specific foods (fatty fish, olive oil, processed meat, fried/sugary items)
 *      move the score even when the stored nutrients don't capture them.
 *
 * Per-meal contributions are summed per local day and clamped to a −10 … +10
 * band (anti- → pro-inflammatory). Weights are intentionally simple constants,
 * documented and tunable; the unit tests assert directionality and clamping
 * rather than exact magnitudes so the weights can be refined against real data.
 */
class InflammationScoreService
{
    /** Final daily band. */
    public const MIN = -10.0;
    public const MAX = 10.0;

    /** Pro-inflammatory macro weights (per gram, except sodium per gram of Na). */
    private const MACRO_PRO = [
        'saturatedFat' => 0.20,
        'sugar'        => 0.05,
    ];
    private const SODIUM_PER_GRAM = 0.5; // sodium stored in mg → divided by 1000

    /** Anti-inflammatory macro weight (per gram). */
    private const FIBER_WEIGHT = 0.25;

    /**
     * Anti-inflammatory micronutrients: weight applied to the fraction of the
     * RDA supplied (capped at one RDA so a single fortified item can't dominate).
     * RDA values mirror SupplementRecommendationService.
     */
    private const MICRO_ANTI = [
        'vitaminA'   => ['rda' => 800.0,  'weight' => 1.0],
        'vitaminC'   => ['rda' => 90.0,   'weight' => 1.0],
        'vitaminD'   => ['rda' => 20.0,   'weight' => 1.0],
        'vitaminE'   => ['rda' => 15.0,   'weight' => 1.0],
        'vitaminB6'  => ['rda' => 1.7,    'weight' => 0.5],
        'vitaminB9'  => ['rda' => 400.0,  'weight' => 0.5],
        'vitaminB12' => ['rda' => 2.4,    'weight' => 0.5],
        'magnesium'  => ['rda' => 400.0,  'weight' => 0.5],
        'zinc'       => ['rda' => 11.0,   'weight' => 0.5],
        'selenium'   => ['rda' => 55.0,   'weight' => 0.5],
    ];

    /**
     * Food-category adjustments: keyword (matched as a substring of a lower-cased
     * ingredient name) → signed weight. Pro-inflammatory positive, anti negative.
     */
    private const FOOD_KEYWORDS = [
        // pro-inflammatory
        'bacon' => 2.0, 'sausage' => 2.0, 'hot dog' => 2.0, 'hotdog' => 2.0,
        'salami' => 2.0, 'pepperoni' => 2.0, 'deli meat' => 2.0, 'bologna' => 2.0,
        'soda' => 2.0, 'cola' => 2.0, 'soft drink' => 2.0, 'energy drink' => 2.0,
        'fried' => 1.5, 'french fries' => 1.5, 'doughnut' => 1.5, 'donut' => 1.5,
        'pastry' => 1.0, 'cake' => 1.0, 'cookie' => 1.0, 'candy' => 1.0,
        'white bread' => 1.0, 'margarine' => 1.0, 'shortening' => 1.0,
        // anti-inflammatory
        'salmon' => -2.0, 'sardine' => -2.0, 'mackerel' => -2.0, 'anchovy' => -2.0,
        'herring' => -2.0, 'trout' => -2.0,
        'spinach' => -1.5, 'kale' => -1.5, 'chard' => -1.5, 'arugula' => -1.5,
        'collard' => -1.5, 'blueberr' => -1.5, 'strawberr' => -1.5, 'raspberr' => -1.5,
        'broccoli' => -1.0, 'cauliflower' => -1.0, 'brussels' => -1.0, 'cabbage' => -1.0,
        'walnut' => -1.0, 'almond' => -1.0, 'chia' => -1.0, 'flax' => -1.0,
        'turmeric' => -1.0, 'ginger' => -1.0, 'garlic' => -1.0, 'green tea' => -1.0,
        'olive oil' => -1.0, 'avocado' => -0.5, 'lentil' => -0.5, 'chickpea' => -0.5,
    ];

    /** Caps the food-keyword contribution of a single meal in either direction. */
    private const FOOD_CAP = 5.0;

    public function __construct(
        private readonly MealEntryRepository $meals,
        private readonly DailyIntake $intake,
        // Factor labels are translated at production time (request locale), so
        // every consumer — dashboard chips, Trends tooltips — localizes at once.
        // Aggregation keys stay consistent within a request.
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Daily inflammation scores for the range, keyed by local 'Y-m-d'. Only days
     * with at least one scored meal are returned.
     *
     * @return array<string, array{
     *     date: string, score: float, band: string, factors: list<array{label: string, value: float}>
     * }>
     */
    public function dailyScores(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->dailyScoresForMeals($user, $this->meals->findWithNutritionForRange($user, $from, $to));
    }

    /**
     * {@see dailyScores()} over meals the caller already holds — the dashboard
     * has today's meals loaded (nutrition fetch-joined), so re-querying them for
     * the score would fetch the same rows twice.
     *
     * @param iterable<MealEntry> $meals the user's meals, nutrition loaded
     *
     * @return array<string, array{
     *     date: string, score: float, band: string, factors: list<array{label: string, value: float}>
     * }>
     */
    public function dailyScoresForMeals(User $user, iterable $meals): array
    {
        // Read twice (the meal scores, then the day's intake), so a generator
        // can't be used up by the first pass.
        $meals = is_array($meals) ? $meals : iterator_to_array($meals, false);

        /** @var array<string, array{raw: float, factors: array<string, float>}> $acc */
        $acc = [];

        foreach ($meals as $meal) {
            $nutrition = $meal->getNutritionData();
            if ($nutrition === null) {
                continue;
            }

            $date   = $meal->getLoggedAt()->format('Y-m-d');
            $scored = $this->scoreMeal($nutrition->toArray(), $meal->getIngredients());

            $acc[$date] ??= ['raw' => 0.0, 'factors' => []];
            $acc[$date]['raw'] += $scored['score'];
            foreach ($scored['factors'] as $factor) {
                $acc[$date]['factors'][$factor['label']] =
                    ($acc[$date]['factors'][$factor['label']] ?? 0.0) + $factor['value'];
            }
        }

        // Fold in the supplements the day's intake counts: score their combined
        // per-day profile (sodium, saturated fat, sugar → pro; fiber + antioxidant
        // micros → anti) through the same engine and add it to each scored day.
        // Factors are suffixed so the tooltip shows the supplement's contribution.
        foreach ($this->intake->forMeals($user, $meals) as $date => $day) {
            if ($day->supplements === [] || !isset($acc[$date])) {
                continue;
            }
            $suppScored = $this->scoreMeal($day->supplements, []);
            if ($suppScored['factors'] === []) {
                continue;
            }
            $acc[$date]['raw'] += $suppScored['score'];
            foreach ($suppScored['factors'] as $factor) {
                $label = $this->translator
                    ->trans('inflammation.factor.supplement_suffix', ['label' => $factor['label']]);
                $acc[$date]['factors'][$label] =
                    ($acc[$date]['factors'][$label] ?? 0.0) + $factor['value'];
            }
        }

        ksort($acc);

        $out = [];
        foreach ($acc as $date => $day) {
            $score = $this->clampDaily($day['raw']);

            $factors = [];
            foreach ($day['factors'] as $label => $value) {
                $factors[] = ['label' => $label, 'value' => round($value, 2)];
            }
            usort($factors, fn(array $a, array $b): int => abs($b['value']) <=> abs($a['value']));

            $out[$date] = [
                'date'    => $date,
                'score'   => $score,
                'band'    => self::band($score),
                'factors' => array_slice($factors, 0, 5),
            ];
        }

        return $out;
    }

    /**
     * Scores a single meal from its nutrient map (NutritionData::toArray() shape)
     * and ingredient list. Returns the signed contribution plus the individual
     * factors that drove it (largest-magnitude first) for explainability.
     *
     * @param  array<string, float|null> $nutrients
     * @param  array<array{name?: string}> $ingredients
     * @return array{score: float, factors: list<array{label: string, value: float}>}
     */
    public function scoreMeal(array $nutrients, array $ingredients): array
    {
        $factors = [];

        foreach (self::MACRO_PRO as $key => $weight) {
            $grams = (float) ($nutrients[$key] ?? 0.0);
            if ($grams > 0) {
                $factors[$this->macroLabel($key)] = $grams * $weight;
            }
        }

        $sodiumG = (float) ($nutrients['sodium'] ?? 0.0) / 1000.0;
        if ($sodiumG > 0) {
            $factors[$this->translator->trans('inflammation.factor.sodium')] = $sodiumG * self::SODIUM_PER_GRAM;
        }

        $fiber = (float) ($nutrients['fiber'] ?? 0.0);
        if ($fiber > 0) {
            $factors[$this->translator->trans('inflammation.factor.fiber')] = -$fiber * self::FIBER_WEIGHT;
        }

        foreach (self::MICRO_ANTI as $key => $meta) {
            $amount = (float) ($nutrients[$key] ?? 0.0);
            if ($amount > 0) {
                $fraction = min($amount / $meta['rda'], 1.0);
                $factors[$this->macroLabel($key)] = -$fraction * $meta['weight'];
            }
        }

        $food = $this->foodAdjustments($ingredients);
        foreach ($food as $label => $value) {
            $factors[$label] = ($factors[$label] ?? 0.0) + $value;
        }

        $score = array_sum($factors);

        // Largest-magnitude factors first.
        $list = [];
        foreach ($factors as $label => $value) {
            $list[] = ['label' => $label, 'value' => round($value, 2)];
        }
        usort($list, fn(array $a, array $b): int => abs($b['value']) <=> abs($a['value']));

        return ['score' => $score, 'factors' => $list];
    }

    /**
     * Maps a raw daily sum onto the clamped −10 … +10 band.
     */
    public function clampDaily(float $raw): float
    {
        return round(max(self::MIN, min(self::MAX, $raw)), 1);
    }

    /**
     * Coarse band for color-coding a score.
     */
    public static function band(float $score): string
    {
        return match (true) {
            $score <= -3.0 => 'strong-anti',
            $score <= -1.0 => 'mild-anti',
            $score < 1.0   => 'neutral',
            $score < 3.0   => 'mild-pro',
            default        => 'strong-pro',
        };
    }

    /**
     * @param  array<array{name?: string}> $ingredients
     * @return array<string, float> label => signed adjustment (capped)
     */
    private function foodAdjustments(array $ingredients): array
    {
        $adjustments = [];
        foreach ($ingredients as $ingredient) {
            $name = strtolower(trim((string) ($ingredient['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            foreach (self::FOOD_KEYWORDS as $keyword => $weight) {
                if (str_contains($name, $keyword)) {
                    $label = $this->translator->trans('inflammation.factor.food', [
                        'name' => $this->translator
                            ->trans('inflammation.keyword.' . str_replace(' ', '_', $keyword)),
                    ]);
                    $adjustments[$label] = ($adjustments[$label] ?? 0.0) + $weight;
                }
            }
        }

        // Cap total food influence per meal.
        $total = array_sum($adjustments);
        if (abs($total) > self::FOOD_CAP && $total !== 0.0) {
            $scale = self::FOOD_CAP / abs($total);
            foreach ($adjustments as $label => $value) {
                $adjustments[$label] = $value * $scale;
            }
        }

        return $adjustments;
    }

    private function macroLabel(string $key): string
    {
        // Every MACRO_PRO / MICRO_ANTI key has an inflammation.factor.* entry in
        // the catalogs (en reproduces the historical English labels verbatim).
        return $this->translator->trans('inflammation.factor.' . $key);
    }
}

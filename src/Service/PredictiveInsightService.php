<?php

declare(strict_types=1);

namespace App\Service;

use App\Ai\AiClient;
use App\Ai\AiFeature;
use App\Dto\PredictiveInsight;
use App\Dto\ScenarioRequest;
use App\Entity\Favorite;
use App\Entity\NutritionData;
use App\Entity\Supplement;
use App\Entity\User;
use App\Enum\HealthMetricType;
use App\Enum\ScenarioType;
use App\Intake\DailyIntake;
use App\Repository\FavoriteRepository;
use App\Repository\HealthMetricRepository;
use App\Repository\MealEntryRepository;
use App\Repository\SupplementRepository;
use App\Repository\SymptomDayRepository;
use App\Repository\SymptomTypeRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PredictiveInsightService
{
    private const BASELINE_DAYS = 30;
    private const MIN_MEAL_DAYS = 3;
    private const KCAL_PER_KG   = 7700.0;
    // Roomy enough that the required closing "educational estimate, not medical advice"
    // sentence is never truncated mid-sentence, even at the chattier tone settings.
    private const NARRATIVE_MAX_TOKENS = 600;

    public function __construct(
        private readonly DailyIntake $intake,
        private readonly MealEntryRepository $mealRepo,
        private readonly SupplementRepository $supplementRepo,
        private readonly HealthMetricRepository $healthMetricRepo,
        private readonly SymptomDayRepository $symptomDayRepo,
        private readonly SymptomTypeRepository $symptomTypeRepo,
        private readonly InflammationScoreService $inflammation,
        private readonly SupplementRecommendationService $supplementRecs,
        private readonly FavoriteRepository $favoriteRepo,
        private readonly UserTime $userTime,
        private readonly TonePromptBuilder $tone,
        #[Autowire(lazy: true)]
        private readonly AiClient $ai,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
        private readonly \App\Service\SymptomLabeler $symptomLabeler,
        private readonly UnitConverter $units,
        private readonly LocaleFormatter $localeFormatter,
        private readonly LocalePromptBuilder $localePrompt,
    ) {
    }

    public function compute(User $user, ScenarioRequest $req): PredictiveInsight
    {
        $baseline = $this->fetchBaseline($user);

        return match ($req->type) {
            ScenarioType::FavoriteDaily    => $this->projectFavoriteDaily($user, $baseline, $req),
            ScenarioType::SupplementAdd    => $this->projectSupplementAdd($user, $baseline, $req),
            ScenarioType::SupplementRemove => $this->projectSupplementRemove($user, $baseline, $req),
            ScenarioType::FreeText         => $this->projectFreeText($user, $baseline, $req),
        };
    }

    /**
     * @return array{
     *   foodAverages: array<string, float>,
     *   supplementDaily: array<string, float>,
     *   avgInflScore: float,
     *   latestWeight: float|null,
     *   symptomAvgs: array<int, float>,
     *   symptomLabels: array<int, string>,
     *   mealDayCount: int,
     *   avgMealsPerDay: float,
     * }
     */
    public function fetchBaseline(User $user): array
    {
        $now  = $this->userTime->now($user);
        $from = $this->userTime->startOfDaysAgo($user, self::BASELINE_DAYS);

        // Food and the supplement stack stay apart (a favorite swaps a food meal,
        // a scenario adds or drops a supplement); `intake` is the two together,
        // which is what every displayed figure and the gap check read. With no
        // meal days there is no intake, supplements included (ADR 0002).
        $average = $this->intake->average($user, $from, $this->userTime->startOfTomorrow($user));
        $foodAverages = $average?->food ?? array_fill_keys(NutritionData::FIELDS, 0.0);
        $supplementDaily = $average?->supplements ?? [];

        // dailyScores already folds in active supplements — baseline average reflects
        // current food + supplement stack together (used in all projection branches).
        $dailyScores = $this->inflammation->dailyScores($user, $from, $now);
        $mealDayCount = count($dailyScores);
        $avgInflScore = $mealDayCount > 0
            ? array_sum(array_column($dailyScores, 'score')) / $mealDayCount
            : 0.0;

        // Average meals logged per day with food — lets the favorite-daily scenario
        // model a meal *replacing* an average meal rather than piling on top of the day.
        $mealCount = count($this->mealRepo->findWithNutritionForRange($user, $from, $now));
        $avgMealsPerDay = $mealDayCount > 0 ? $mealCount / $mealDayCount : 0.0;

        $latestWeight = $this->healthMetricRepo->findLatestValue($user, HealthMetricType::Weight);

        // Symptom averages: mean level per enabled type over the last 30 days.
        $levelsByType = $this->symptomDayRepo->getLevelsByTypeForRange($user, $from, $now);
        $enabledTypes = $this->symptomTypeRepo->findEnabledByUserOrdered($user);

        $symptomAvgs   = [];
        $symptomLabels = [];
        foreach ($enabledTypes as $type) {
            $tid    = (int) $type->getId();
            $levels = $levelsByType[$tid] ?? [];
            $symptomLabels[$tid] = $this->symptomLabeler->label($type);
            $symptomAvgs[$tid]   = $levels !== [] ? array_sum($levels) / count($levels) : 0.0;
        }

        return [
            'foodAverages'    => $foodAverages,
            'supplementDaily' => $supplementDaily,
            'intake'          => $this->mergeAdd($foodAverages, $supplementDaily),
            'avgInflScore'    => $this->inflammation->clampDaily($avgInflScore),
            'latestWeight'    => $latestWeight,
            'symptomAvgs'     => $symptomAvgs,
            'symptomLabels'   => $symptomLabels,
            'mealDayCount'    => $mealDayCount,
            'avgMealsPerDay'  => $avgMealsPerDay,
        ];
    }

    private function projectFavoriteDaily(User $user, array $baseline, ScenarioRequest $req): PredictiveInsight
    {
        /** @var Favorite $fav */
        $fav = $this->favoriteRepo->find($req->favoriteId);

        $favNutrients   = array_map('floatval', $fav->getNutrients());
        $favIngredients = $fav->getIngredients();

        // New daily food average: the favorite *replaces* an average meal (displacement),
        // so subtract one average meal's nutrients before adding the favorite's. Falls
        // back to a plain add when we can't estimate meals/day (no baseline meals).
        $mealsPerDay = $baseline['avgMealsPerDay'];
        $perMealNutrients = $mealsPerDay > 0
            ? array_map(static fn (float $v): float => $v / $mealsPerDay, $baseline['foodAverages'])
            : [];
        $projectedFood = $this->mergeAdd(
            $this->mergeSubtract($baseline['foodAverages'], $perMealNutrients),
            $favNutrients,
        );

        // Supplement stack unchanged.
        $projectedSuppDaily = $baseline['supplementDaily'];

        // Eating a favorite "every day" realistically *replaces* one of the day's
        // meals, not adds a meal on top. So model displacement: remove one average
        // meal's contribution, then add the favorite's. Supplements are constant and
        // already folded into the baseline, so subtract them out before dividing the
        // day's score across meals (the favorite swaps a *food* meal, not the stack).
        $favScored = $this->inflammation->scoreMeal($favNutrients, $favIngredients);
        $suppScore = $baseline['supplementDaily'] !== []
            ? $this->inflammation->scoreMeal($baseline['supplementDaily'], [])['score']
            : 0.0;
        $foodPortionOfDay   = $baseline['avgInflScore'] - $suppScore;
        $displacedMealScore = $mealsPerDay > 0 ? $foodPortionOfDay / $mealsPerDay : 0.0;
        $projectedInflScore = $this->inflammation->clampDaily(
            $baseline['avgInflScore'] - $displacedMealScore + $favScored['score']
        );

        $projectedIntake = $this->mergeAdd($projectedFood, $projectedSuppDaily);
        $projectedGaps = $this->supplementRecs->computeGaps($projectedIntake);
        $baselineGaps  = $this->baselineGaps($baseline);

        $beforeAfter = $this->buildBeforeAfter(
            $user,
            $baseline,
            $projectedIntake,
            $projectedInflScore,
            $projectedGaps,
            $baselineGaps,
            $req->dimensions,
            $req->durationWeeks,
        );
        $narrative = $this->buildNarrative(
            $user,
            $baseline,
            $projectedIntake,
            $projectedInflScore,
            $projectedGaps,
            $req,
            $fav->getName(),
        );

        return new PredictiveInsight(
            narrative: $narrative,
            beforeAfter: $beforeAfter,
            confidenceNote: $this->translator->trans('predict.note.computed'),
            isFreeTextEstimate: false,
        );
    }

    private function projectSupplementAdd(User $user, array $baseline, ScenarioRequest $req): PredictiveInsight
    {
        /** @var Supplement $supp */
        $supp = $this->supplementRepo->find($req->supplementId);

        $suppNutrients = $supp->dailyNutrients();

        // New supplement is not yet active, so its contribution is purely additive
        // to the current stack (which is already baked into the baseline avg score).
        $projectedSuppDaily = $this->mergeAdd($baseline['supplementDaily'], $suppNutrients);
        $projectedFood      = $baseline['foodAverages'];

        $suppScored = $this->inflammation->scoreMeal($suppNutrients, []);
        $projectedInflScore = $this->inflammation->clampDaily(
            $baseline['avgInflScore'] + $suppScored['score']
        );

        $projectedIntake = $this->mergeAdd($projectedFood, $projectedSuppDaily);
        $projectedGaps = $this->supplementRecs->computeGaps($projectedIntake);
        $baselineGaps  = $this->baselineGaps($baseline);

        $beforeAfter = $this->buildBeforeAfter(
            $user,
            $baseline,
            $projectedIntake,
            $projectedInflScore,
            $projectedGaps,
            $baselineGaps,
            $req->dimensions,
            $req->durationWeeks,
        );
        $narrative = $this->buildNarrative(
            $user,
            $baseline,
            $projectedIntake,
            $projectedInflScore,
            $projectedGaps,
            $req,
            $supp->getName(),
        );

        return new PredictiveInsight(
            narrative: $narrative,
            beforeAfter: $beforeAfter,
            confidenceNote: $this->translator->trans('predict.note.gaps'),
            isFreeTextEstimate: false,
        );
    }

    private function projectSupplementRemove(User $user, array $baseline, ScenarioRequest $req): PredictiveInsight
    {
        /** @var Supplement $supp */
        $supp = $this->supplementRepo->find($req->supplementId);

        $suppNutrients = $supp->dailyNutrients();

        // This supplement IS active and already baked into the baseline avg score
        // (dailyScores folds in active supplements). Remove its contribution.
        $projectedSuppDaily = $this->mergeSubtract($baseline['supplementDaily'], $suppNutrients);
        $projectedFood      = $baseline['foodAverages'];

        $suppScored = $this->inflammation->scoreMeal($suppNutrients, []);
        $projectedInflScore = $this->inflammation->clampDaily(
            $baseline['avgInflScore'] - $suppScored['score']
        );

        $projectedIntake = $this->mergeAdd($projectedFood, $projectedSuppDaily);
        $projectedGaps = $this->supplementRecs->computeGaps($projectedIntake);
        $baselineGaps  = $this->baselineGaps($baseline);

        $beforeAfter = $this->buildBeforeAfter(
            $user,
            $baseline,
            $projectedIntake,
            $projectedInflScore,
            $projectedGaps,
            $baselineGaps,
            $req->dimensions,
            $req->durationWeeks,
        );
        $narrative = $this->buildNarrative(
            $user,
            $baseline,
            $projectedIntake,
            $projectedInflScore,
            $projectedGaps,
            $req,
            $supp->getName(),
        );

        return new PredictiveInsight(
            narrative: $narrative,
            beforeAfter: $beforeAfter,
            confidenceNote: $this->translator->trans('predict.note.removed'),
            isFreeTextEstimate: false,
        );
    }

    private function projectFreeText(User $user, array $baseline, ScenarioRequest $req): PredictiveInsight
    {
        // No in-memory math available for a free-text scenario — Claude reasons
        // qualitatively over the baseline numbers and the described change.
        $narrative = $this->buildFreeTextNarrative($user, $baseline, $req);

        $beforeAfter = [];
        if (in_array('inflammation', $req->dimensions, true)) {
            $beforeAfter[] = [
                'dimension'      => 'inflammation',
                'label'          => $this->translator->trans('predict.row.infl_avg'),
                'currentValue'   => $this->localeFormatter->number(round($baseline['avgInflScore'], 1), 1),
                'projectedValue' => $this->translator->trans('predict.row.see_narrative'),
                'unit'           => '/ 10',
                'direction'      => 'neutral',
            ];
        }

        return new PredictiveInsight(
            narrative: $narrative,
            beforeAfter: $beforeAfter,
            confidenceNote: $this->translator->trans('predict.note.qualitative'),
            isFreeTextEstimate: true,
        );
    }

    /** @param array<string, mixed> $baseline */
    private function baselineGaps(array $baseline): array
    {
        return $this->supplementRecs->computeGaps($baseline['intake']);
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, float> $projectedIntake food plus supplements, projected
     * @param list<array{label: string}> $projectedGaps
     * @param list<array{label: string}> $baselineGaps
     * @param list<string> $dimensions
     * @return list<array{
     *   dimension: string, label: string, currentValue: float|string,
     *   projectedValue: float|string, unit: string, direction: string
     * }>
     */
    private function buildBeforeAfter(
        User $user,
        array $baseline,
        array $projectedIntake,
        float $projectedInflScore,
        array $projectedGaps,
        array $baselineGaps,
        array $dimensions,
        int $durationWeeks,
    ): array {
        $rows = [];

        if (in_array('inflammation', $dimensions, true)) {
            $current   = round($baseline['avgInflScore'], 1);
            $projected = round($projectedInflScore, 1);
            $rows[] = [
                'dimension'      => 'inflammation',
                'label'          => $this->translator->trans('predict.row.infl_avg'),
                'currentValue'   => $this->localeFormatter->number($current, 1),
                'projectedValue' => $this->localeFormatter->number($projected, 1),
                'unit'           => '/ 10',
                'direction'      => $this->inflDirection($current, $projected),
            ];
        }

        if (in_array('nutrition', $dimensions, true)) {
            $macros = [
                'calories'      => [$this->translator->trans('nutrient_full.calories'), 'kcal/day'],
                'protein'       => [$this->translator->trans('nutrient_full.protein'), 'g/day'],
                'fiber'         => [$this->translator->trans('nutrient_full.fiber'), 'g/day'],
                'carbohydrates' => [$this->translator->trans('nutrient_full.carbohydrates'), 'g/day'],
                'fat'           => [$this->translator->trans('nutrient_full.fat'), 'g/day'],
            ];
            foreach ($macros as $key => [$label, $unit]) {
                $current   = round((float) ($baseline['intake'][$key] ?? 0), 1);
                $projected = round((float) ($projectedIntake[$key] ?? 0), 1);
                $rows[] = [
                    'dimension'      => 'nutrition',
                    'label'          => $label,
                    'currentValue'   => $this->localeFormatter->number($current, 1),
                    'projectedValue' => $this->localeFormatter->number($projected, 1),
                    'unit'           => $unit,
                    'direction'      => $this->nutritionDirection($key, $current, $projected),
                ];
            }

            // A gap comparison needs a real baseline: with no logged nutrition and
            // no active supplements the "before" is computeGaps' empty-input []
            // (see its all-zero guard), and "0 → N gaps" would misread as the
            // scenario making nutrition worse. Skip the row instead.
            if ($this->supplementRecs->hasNutritionData($baseline['intake'])) {
                $baselineGapCount  = count($baselineGaps);
                $projectedGapCount = count($projectedGaps);
                $gapDirection = match (true) {
                    $projectedGapCount < $baselineGapCount => 'better',
                    $projectedGapCount > $baselineGapCount => 'worse',
                    default                                => 'neutral',
                };
                $currentGaps = $this->translator
                    ->trans('predict.row.gaps_count', ['count' => $baselineGapCount]);
                $projectedGapsValue = $this->translator
                    ->trans('predict.row.gaps_count', ['count' => $projectedGapCount]);
                $rows[] = [
                    'dimension'      => 'nutrition',
                    'label'          => $this->translator->trans('predict.row.gaps'),
                    'currentValue'   => $currentGaps,
                    'projectedValue' => $projectedGapsValue,
                    'unit'           => '',
                    'direction'      => $gapDirection,
                ];
            }
        }

        if (in_array('weight', $dimensions, true) && $baseline['latestWeight'] !== null) {
            // ~7,700 kcal ≈ 1 kg of body mass; weight is stored in kg. Project the daily
            // calorie delta over the selected window, not a hard-coded 4-week horizon.
            $currentCalories = (float) ($baseline['intake']['calories'] ?? 0);
            $calorieDelta    = (float) ($projectedIntake['calories'] ?? 0) - $currentCalories;
            $kgChange        = ($calorieDelta * 7 * $durationWeeks) / self::KCAL_PER_KG;

            // Stored/projected in canonical kg — convert for display per the
            // user's Settings ▸ Units preference (imperial users see lb).
            $system = $user->getUnitSystem();
            $rows[] = [
                'dimension'      => 'weight',
                'label'          => $this->translator->trans('predict.row.weight_after', ['weeks' => $durationWeeks]),
                'currentValue'   => $this->localeFormatter->number(
                    $this->units->toDisplay(HealthMetricType::Weight, (float) $baseline['latestWeight'], $system),
                    1,
                ),
                'projectedValue' => $this->localeFormatter->number(
                    $this->units->toDisplay(HealthMetricType::Weight, $baseline['latestWeight'] + $kgChange, $system),
                    1,
                ),
                'unit'           => $this->units->displayUnit(HealthMetricType::Weight, $system),
                'direction'      => 'neutral',
            ];
        }

        if (in_array('symptoms', $dimensions, true) && $baseline['symptomAvgs'] !== []) {
            $inflDirection = match (true) {
                $projectedInflScore < $baseline['avgInflScore'] => 'better',
                $projectedInflScore > $baseline['avgInflScore'] => 'worse',
                default                                         => 'neutral',
            };
            foreach ($baseline['symptomAvgs'] as $tid => $avg) {
                $label = $baseline['symptomLabels'][$tid] ?? 'Symptom';
                $rows[] = [
                    'dimension'      => 'symptoms',
                    'label'          => $this->translator->trans('predict.row.symptom_trend', ['label' => $label]),
                    'currentValue'   => $this->localeFormatter->number(round($avg, 1), 1) . ' / 10',
                    'projectedValue' => $this->translator->trans('predict.row.see_narrative'),
                    'unit'           => '',
                    'direction'      => $inflDirection,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, float> $projectedIntake food plus supplements, projected
     * @param list<array{label: string}> $projectedGaps
     */
    private function buildNarrative(
        User $user,
        array $baseline,
        array $projectedIntake,
        float $projectedInflScore,
        array $projectedGaps,
        ScenarioRequest $req,
        string $itemName = '',
    ): string {
        $gapLabels = array_column($projectedGaps, 'label');

        $scenarioDesc = match ($req->type) {
            ScenarioType::FavoriteDaily => sprintf(
                'Eating "%s" every day for %d week(s)',
                $itemName ?: 'this favorite',
                $req->durationWeeks,
            ),
            ScenarioType::SupplementAdd => sprintf(
                'Adding "%s" to daily supplements for %d week(s)',
                $itemName,
                $req->durationWeeks,
            ),
            ScenarioType::SupplementRemove => sprintf(
                'Removing "%s" from daily supplements for %d week(s)',
                $itemName,
                $req->durationWeeks,
            ),
            ScenarioType::FreeText => $req->freeText ?? '',
        };

        $system = <<<'SYS'
            You are a nutrition assistant for NouriLoop. You are given a "what if" scenario and computed
            before/after projections for a user's nutrition metrics. Write a short, plain-prose narrative
            (3–5 sentences) explaining what the numbers mean in practical terms — what the user might notice,
            what is uncertain, and any relevant nuance. Do not use Markdown formatting. Do not repeat the
            numbers verbatim; refer to them by direction and magnitude. End with one sentence noting that this
            is an educational estimate, not medical advice.
            SYS;

        $baselineGapLabels = array_column($this->baselineGaps($baseline), 'label');

        $userMessage = sprintf(
            "Scenario: %s\nDuration: %d week(s)\n\n"
                . "Baseline averages (30 days):\n"
                . "- Inflammation score: %.1f / 10\n- Calories: %.0f kcal/day\n"
                . "- Protein: %.1f g/day\n- Fiber: %.1f g/day\n- Nutrient gaps (< 75%% RDA): %s\n\n"
                . "Projected values after %d week(s):\n"
                . "- Inflammation score: %.1f / 10\n- Calories: %.0f kcal/day\n"
                . "- Protein: %.1f g/day\n- Fiber: %.1f g/day\n- Remaining nutrient gaps: %s",
            $scenarioDesc,
            $req->durationWeeks,
            $baseline['avgInflScore'],
            (float) ($baseline['intake']['calories'] ?? 0),
            (float) ($baseline['intake']['protein'] ?? 0),
            (float) ($baseline['intake']['fiber'] ?? 0),
            $baselineGapLabels !== [] ? implode(', ', $baselineGapLabels) : 'none',
            $req->durationWeeks,
            $projectedInflScore,
            round((float) ($projectedIntake['calories'] ?? 0)),
            (float) ($projectedIntake['protein'] ?? 0),
            (float) ($projectedIntake['fiber'] ?? 0),
            $gapLabels !== [] ? implode(', ', $gapLabels) : 'none',
        );

        try {
            $system .= ' ' . $this->localePrompt->forUser($user) . $this->tone->forUser($user);
            $result = $this->ai->complete(
                AiFeature::Predict,
                $user,
                $system,
                [['role' => 'user', 'content' => $userMessage]],
                $this->localePrompt->maxTokens($user->getLocale(), self::NARRATIVE_MAX_TOKENS),
            );
            return $result->text;
        } catch (\Throwable) {
            return $this->fallbackNarrative($baseline, $projectedIntake, $projectedInflScore);
        }
    }

    /** @param array<string, mixed> $baseline */
    private function buildFreeTextNarrative(User $user, array $baseline, ScenarioRequest $req): string
    {
        $gapLabels = array_column($this->baselineGaps($baseline), 'label');

        $system = <<<'SYS'
            You are a nutrition assistant for NouriLoop. A user has described a hypothetical dietary change.
            Based on their real 30-day baseline data, reason qualitatively about what nutritional changes they
            might expect. Be specific about which dimensions (inflammation, nutrition, weight, symptoms) are
            likely to change and in which direction. Write 3–5 sentences in plain prose for a non-technical
            reader. Do not use Markdown formatting. End with one sentence noting that this is a qualitative AI
            estimate, not medical advice.
            SYS;

        $userMessage = sprintf(
            "User's scenario: %s\nDuration: %d week(s)\n\n"
                . "User's 30-day baseline:\n"
                . "- Inflammation score: %.1f / 10\n- Calories: %.0f kcal/day\n"
                . "- Protein: %.1f g/day\n- Fiber: %.1f g/day\n- Nutrient gaps (< 75%% RDA): %s",
            $req->freeText,
            $req->durationWeeks,
            $baseline['avgInflScore'],
            (float) ($baseline['intake']['calories'] ?? 0),
            (float) ($baseline['intake']['protein'] ?? 0),
            (float) ($baseline['intake']['fiber'] ?? 0),
            $gapLabels !== [] ? implode(', ', $gapLabels) : 'none',
        );

        try {
            $system .= ' ' . $this->localePrompt->forUser($user) . $this->tone->forUser($user);
            $result = $this->ai->complete(
                AiFeature::Predict,
                $user,
                $system,
                [['role' => 'user', 'content' => $userMessage]],
                $this->localePrompt->maxTokens($user->getLocale(), self::NARRATIVE_MAX_TOKENS),
            );
            return $result->text;
        } catch (\Throwable) {
            return $this->translator->trans('predict.free_text_failed');
        }
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, float> $projectedIntake food plus supplements, projected
     */
    private function fallbackNarrative(array $baseline, array $projectedIntake, float $projectedInflScore): string
    {
        $inflDelta = round($projectedInflScore - $baseline['avgInflScore'], 1);
        $currentCalories = (float) ($baseline['intake']['calories'] ?? 0);
        $calDelta  = round((float) ($projectedIntake['calories'] ?? 0) - $currentCalories);

        $parts = [];
        if (abs($inflDelta) >= 0.1) {
            $parts[] = $this->translator->trans('predict.fallback.infl', [
                'direction' => $inflDelta < 0 ? 'down' : 'up',
                'points'    => sprintf('%.1f', abs($inflDelta)),
                'score'     => sprintf('%.1f', $projectedInflScore),
            ]);
        }
        if (abs($calDelta) >= 10) {
            $parts[] = $this->translator->trans('predict.fallback.calories', [
                'direction' => $calDelta > 0 ? 'up' : 'down',
                'kcal'      => sprintf('%.0f', abs($calDelta)),
            ]);
        }
        $parts[] = $this->translator->trans('predict.fallback.closing');

        return implode(' ', $parts);
    }

    /** @param array<string, float> $a @param array<string, float> $b */
    private function mergeAdd(array $a, array $b): array
    {
        $result = $a;
        foreach ($b as $key => $val) {
            $result[$key] = ($result[$key] ?? 0.0) + $val;
        }
        return $result;
    }

    /** @param array<string, float> $a @param array<string, float> $b */
    private function mergeSubtract(array $a, array $b): array
    {
        $result = $a;
        foreach ($b as $key => $val) {
            $result[$key] = max(0.0, ($result[$key] ?? 0.0) - $val);
        }
        return $result;
    }

    private function inflDirection(float $current, float $projected): string
    {
        // Lower inflammation is better.
        if ($projected < $current - 0.05) {
            return 'better';
        }
        if ($projected > $current + 0.05) {
            return 'worse';
        }
        return 'neutral';
    }

    private function nutritionDirection(string $key, float $current, float $projected): string
    {
        $delta = $projected - $current;
        if (abs($delta) < 0.05) {
            return 'neutral';
        }
        // Fiber and protein increases are generally better; calorie/fat/carb changes are neutral.
        return match ($key) {
            'fiber', 'protein' => $delta > 0 ? 'better' : 'worse',
            default            => 'neutral',
        };
    }
}

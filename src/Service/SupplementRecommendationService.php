<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Intake\DailyIntake;

class SupplementRecommendationService
{
    // Recommended Daily Allowances (adult averages)
    private const RDA = [
        'fiber'     => ['rda' => 28.0,  'unit' => 'g',   'label' => 'Fiber'],
        'vitaminA'  => ['rda' => 800,   'unit' => 'mcg', 'label' => 'Vitamin A'],
        'vitaminB1' => ['rda' => 1.2,   'unit' => 'mg',  'label' => 'Vitamin B1 (Thiamine)'],
        'vitaminB2' => ['rda' => 1.3,   'unit' => 'mg',  'label' => 'Vitamin B2 (Riboflavin)'],
        'vitaminB3' => ['rda' => 16.0,  'unit' => 'mg',  'label' => 'Vitamin B3 (Niacin)'],
        'vitaminB6' => ['rda' => 1.7,   'unit' => 'mg',  'label' => 'Vitamin B6'],
        'vitaminB9' => ['rda' => 400.0, 'unit' => 'mcg', 'label' => 'Folate (B9)'],
        'vitaminB12' => ['rda' => 2.4,   'unit' => 'mcg', 'label' => 'Vitamin B12'],
        'vitaminC'  => ['rda' => 90.0,  'unit' => 'mg',  'label' => 'Vitamin C'],
        'vitaminD'  => ['rda' => 20.0,  'unit' => 'mcg', 'label' => 'Vitamin D'],
        'vitaminE'  => ['rda' => 15.0,  'unit' => 'mg',  'label' => 'Vitamin E'],
        'vitaminK'  => ['rda' => 120.0, 'unit' => 'mcg', 'label' => 'Vitamin K'],
        'calcium'   => ['rda' => 1000,  'unit' => 'mg',  'label' => 'Calcium'],
        'iron'      => ['rda' => 18.0,  'unit' => 'mg',  'label' => 'Iron'],
        'magnesium' => ['rda' => 400,   'unit' => 'mg',  'label' => 'Magnesium'],
        'potassium' => ['rda' => 3500,  'unit' => 'mg',  'label' => 'Potassium'],
        'zinc'      => ['rda' => 11.0,  'unit' => 'mg',  'label' => 'Zinc'],
        'selenium'  => ['rda' => 55.0,  'unit' => 'mcg', 'label' => 'Selenium'],
    ];

    // Supplement suggestions keyed by nutrient
    private const SUPPLEMENTS = [
        'fiber'     => 'Consider a fiber supplement (psyllium husk) or eat more legumes, whole grains, and vegetables.',
        'vitaminA'  => 'Consider a Vitamin A supplement or increase intake of liver, sweet potato, and carrots.',
        'vitaminB1' => 'Consider a B-complex supplement or eat more whole grains and legumes.',
        'vitaminB2' => 'Consider a B-complex supplement or eat more dairy, eggs, and lean meats.',
        'vitaminB3' => 'Consider a B-complex supplement or eat more poultry, fish, and peanuts.',
        'vitaminB6' => 'Consider a Vitamin B6 supplement or eat more poultry, fish, potatoes, and bananas.',
        'vitaminB9' => 'Consider a Folate or B-complex supplement. Good food sources: leafy greens, beans.',
        'vitaminB12' => 'Consider a Vitamin B12 supplement (especially important for plant-based diets).',
        'vitaminC'  => 'Consider a Vitamin C supplement or eat more citrus, bell peppers, and broccoli.',
        'vitaminD'  => 'Consider a Vitamin D3 supplement — most people are deficient, especially indoors.',
        'vitaminE'  => 'Consider a Vitamin E supplement or eat more nuts, seeds, and vegetable oils.',
        'vitaminK'  => 'Consider a Vitamin K2 supplement or eat more leafy greens and fermented foods.',
        'calcium'   => 'Consider a Calcium supplement or increase dairy, fortified plant milks, and leafy greens.',
        'iron'      => 'Consider an Iron supplement — consult your doctor first as excess iron is harmful.',
        'magnesium' => 'Consider a Magnesium glycinate supplement — commonly deficient and aids sleep.',
        'potassium' => 'Increase potassium-rich foods: bananas, avocados, sweet potatoes, beans.',
        'zinc'      => 'Consider a Zinc supplement or eat more oysters, beef, pumpkin seeds.',
        'selenium'  => 'Two Brazil nuts daily provide the RDA of Selenium, or consider a supplement.',
    ];

    /**
     * Macro/“nutrition facts” fields a supplement can also carry. These are NOT
     * adequacy targets (no RDA gap is reported for them) — they exist so a
     * supplement's full label is captured and counted toward daily intake totals
     * and the inflammation score, the same as food.
     */
    private const MACROS = [
        'calories'      => ['unit' => 'kcal', 'label' => 'Calories'],
        'protein'       => ['unit' => 'g',    'label' => 'Protein'],
        'carbohydrates' => ['unit' => 'g',    'label' => 'Carbohydrates'],
        'fat'           => ['unit' => 'g',    'label' => 'Total Fat'],
        'saturatedFat'  => ['unit' => 'g',    'label' => 'Saturated Fat'],
        'sugar'         => ['unit' => 'g',    'label' => 'Sugar'],
        'sodium'        => ['unit' => 'mg',   'label' => 'Sodium'],
    ];

    public function __construct(
        private readonly DailyIntake $intake,
        private readonly UserTime $userTime,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
    ) {
    }

    /**
     * Nutrient label + unit for each tracked nutrient, keyed by RDA key.
     *
     * @return array<string, array{label: string, unit: string}>
     */
    public function nutrientMeta(): array
    {
        $meta = [];
        foreach (self::RDA as $key => $info) {
            $meta[$key] = ['label' => $this->translator->trans('nutrient_full.' . $key), 'unit' => $info['unit']];
        }

        return $meta;
    }

    /**
     * Macro/nutrition-facts fields (calories, protein, …) a supplement can carry.
     * Captured and counted toward daily totals, but not adequacy targets.
     *
     * @return array<string, array{label: string, unit: string}>
     */
    public function macroMeta(): array
    {
        $meta = [];
        foreach (self::MACROS as $key => $info) {
            $meta[$key] = ['label' => $this->translator->trans('nutrient_full.' . $key), 'unit' => $info['unit']];
        }

        return $meta;
    }

    /**
     * @return array<array{
     *     nutrient: string, label: string, pct: float, rda: float,
     *     average: float, unit: string, suggestion: string
     * }>
     */
    public function getRecommendations(User $user, int $days = 7): array
    {
        return $this->computeGaps($this->intake->average(
            $user,
            $this->userTime->startOfDaysAgo($user, $days),
            $this->userTime->startOfTomorrow($user),
        )?->totals() ?? []);
    }

    /**
     * Whether an average intake carries any nutrition at all, so callers can
     * tell "no gaps" (real data, nothing short) from "no data" (no meal days
     * in the window): Predict skips its gap comparison on an empty baseline,
     * and the supplements page renders a no-data state instead of claiming
     * "no gaps".
     *
     * @param array<string, float> $averageIntake {@see \App\Intake\IntakeAverage::totals()}, or [] for none
     */
    public function hasNutritionData(array $averageIntake): bool
    {
        return array_sum($averageIntake) > 0.0;
    }

    /**
     * The gap calculation itself, from an already-fetched average daily intake
     * (food and supplements together; supplements count only when there are
     * meal days, so an empty window has no gaps). Callers that already hold
     * the average (e.g. TrendsController) call this directly.
     *
     * @param array<string, float> $averageIntake {@see \App\Intake\IntakeAverage::totals()}, or [] for none
     *
     * @return array<array{
     *     nutrient: string, label: string, pct: float, rda: float,
     *     average: float, unit: string, suggestion: string
     * }>
     */
    public function computeGaps(array $averageIntake): array
    {
        // All nutrients are non-negative, so an all-zero (or empty) average
        // means "no data", never "the user ate zero of everything". Without
        // this, a brand-new user saw a gap for every tracked nutrient.
        if (array_sum($averageIntake) == 0.0) {
            return [];
        }
        $gaps = [];

        foreach (self::RDA as $key => $meta) {
            $average = $averageIntake[$key] ?? 0.0;
            $pct = $meta['rda'] > 0 ? ($average / $meta['rda']) * 100 : 0;

            if ($pct < 75) {
                $gaps[] = [
                    'nutrient'   => $key,
                    // Localized at production time (request locale, or the
                    // recipient's inside a delivery LocaleSwitcher wrap); the
                    // const English labels remain as missing-key fallbacks.
                    'label'      => $this->translator->trans('nutrient_full.' . $key),
                    'pct'        => round($pct, 1),
                    'rda'        => $meta['rda'],
                    'average'    => round($average, 2),
                    'unit'       => $meta['unit'],
                    'suggestion' => $this->translator->trans('supp_suggestion.' . $key),
                ];
            }
        }

        usort($gaps, fn($a, $b) => $a['pct'] <=> $b['pct']);

        return $gaps;
    }
}

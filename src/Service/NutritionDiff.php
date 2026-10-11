<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\NutritionDiffRow;
use App\Entity\NutritionData;

/**
 * Compares a meal's stored nutrition against a proposed re-analysis — the
 * "diff before apply" half of the meal-history plan's §4 rule, which exists so
 * a re-analysis can never silently overwrite a number the user corrected by
 * hand.
 *
 * Pure and DB-free (the PortionScaler / TrendCalculator pattern) so the
 * comparison the operator sees is unit-testable on its own, independently of
 * the controller and the Claude call that produced the proposal.
 */
final class NutritionDiff
{
    /**
     * Every comparable nutrient, in display order (macros, then minerals, then
     * vitamins), mapped to its display unit. Keys are NutritionData field names
     * — which double as `nutrient_full.*` label keys — and cover the same set
     * NutritionData persists, because applying a re-analysis replaces all of
     * them: showing a subset while overwriting the rest would hide changes the
     * user is being asked to approve.
     */
    public const ROWS = [
        'calories'      => 'kcal',
        'protein'       => 'g',
        'carbohydrates' => 'g',
        'fat'           => 'g',
        'saturatedFat'  => 'g',
        'fiber'         => 'g',
        'sugar'         => 'g',
        'sodium'        => 'mg',
        'potassium'     => 'mg',
        'calcium'       => 'mg',
        'iron'          => 'mg',
        'magnesium'     => 'mg',
        'phosphorus'    => 'mg',
        'zinc'          => 'mg',
        'selenium'      => 'mcg',
        'vitaminA'      => 'mcg',
        'vitaminC'      => 'mg',
        'vitaminD'      => 'mcg',
        'vitaminE'      => 'mg',
        'vitaminK'      => 'mcg',
        'vitaminB1'     => 'mg',
        'vitaminB2'     => 'mg',
        'vitaminB3'     => 'mg',
        'vitaminB6'     => 'mg',
        'vitaminB9'     => 'mcg',
        'vitaminB12'    => 'mcg',
    ];

    /**
     * The comparison rows. A nutrient neither side has a value for is dropped
     * (an all-null row tells the user nothing); everything else is kept even
     * when unchanged, so the table reads as a full statement of what applying
     * would do — not just a highlight reel.
     *
     * @param array<string, float|null> $proposed a NutritionData::toArray() payload
     *
     * @return list<NutritionDiffRow>
     */
    public function rows(?NutritionData $current, array $proposed): array
    {
        $rows = [];
        foreach (self::ROWS as $field => $unit) {
            $before = $current?->{'get' . ucfirst($field)}();
            $after  = $this->floatOrNull($proposed[$field] ?? null);

            if ($before === null && $after === null) {
                continue;
            }

            $rows[] = new NutritionDiffRow($field, $unit, $before, $after);
        }

        return $rows;
    }

    /**
     * Did the re-analysis actually land on different numbers? Worth knowing
     * explicitly: an identical result still cost the user an allowance slot, so
     * the review screen says so rather than presenting an empty-looking diff as
     * if something were on offer.
     *
     * @param list<NutritionDiffRow> $rows
     */
    public function hasChanges(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row->changed()) {
                return true;
            }
        }

        return false;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}

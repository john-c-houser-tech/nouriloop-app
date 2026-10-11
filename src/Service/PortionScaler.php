<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Shared portion-multiplier math used wherever a stored estimate is scaled to
 * the amount actually eaten — the photo-review save and (later) logging from a
 * saved favorite. Nutrition scaling itself lives in NutritionData::fromArray();
 * this covers the surrounding bits (portion normalization + ingredient amounts)
 * so both paths behave identically.
 */
final class PortionScaler
{
    /**
     * Largest multiplier accepted — the meal edit form's rescale cap. A portion
     * control exists for "I had two helpings", not for scaling a meal into the
     * thousands; anything above is capped here.
     */
    public const MAX_PORTIONS = 100.0;

    /**
     * Coerces a posted portion value to a usable positive multiplier, falling
     * back to 1.0 for empty, zero, negative, or non-finite input ("1e999" casts
     * to INF, which a `> 0` check alone lets through into every stored nutrient)
     * and capping at MAX_PORTIONS.
     */
    public function normalize(mixed $portions): float
    {
        $value = (float) $portions;
        if (!is_finite($value) || $value <= 0) {
            return 1.0;
        }

        return min($value, self::MAX_PORTIONS);
    }

    /**
     * Scales numeric ingredient amounts by the portion multiplier. Non-numeric
     * amounts (e.g. "a pinch") are left untouched.
     *
     * @param  array<array{name: string, amount: string, unit: string}> $ingredients
     * @return array<array{name: string, amount: string, unit: string}>
     */
    public function scaleIngredients(array $ingredients, float $portions): array
    {
        if ($portions === 1.0) {
            return $ingredients;
        }

        return array_map(static function (array $item) use ($portions): array {
            if (isset($item['amount']) && is_numeric($item['amount'])) {
                $item['amount'] = (string) round(((float) $item['amount']) * $portions, 2);
            }

            return $item;
        }, $ingredients);
    }
}

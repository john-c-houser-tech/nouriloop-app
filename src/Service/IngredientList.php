<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The ingredient list in a Claude analysis reply (meal or recipe), normalised to
 * name/amount/unit strings. Anything that isn't an ingredient object is dropped.
 */
final class IngredientList
{
    /**
     * @return list<array{name: string, amount: string, unit: string}>
     */
    public static function fromClaudeJson(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $result[] = [
                'name'   => (string) ($item['name'] ?? ''),
                'amount' => (string) ($item['amount'] ?? ''),
                'unit'   => (string) ($item['unit'] ?? ''),
            ];
        }

        return $result;
    }
}

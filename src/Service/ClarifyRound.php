<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * The clarify-round protocol shared by the meal review and the recipe confirm
 * screen (see CONTEXT.md): how many rounds a draft gets, how the posted
 * ingredient table is read, and what the next analysis is told. Both screens
 * post the same fields: answers[i] for the AI's questions and
 * ing_name[]/ing_amount[]/ing_unit[] for the ingredient table.
 */
final class ClarifyRound
{
    /** Rounds per draft, the first analysis included. More can't help and each one is billed. */
    public const MAX_ROUNDS = 3;

    private const NAME_MAX_LENGTH = 200;
    private const AMOUNT_MAX_LENGTH = 50;

    /** Whether a draft that has had $round rounds may have another. */
    public static function canContinue(int $round): bool
    {
        return $round < self::MAX_ROUNDS;
    }

    /**
     * The posted ingredient table: rows with a blank name dropped, values
     * trimmed and bounded (they go into a billable prompt). Null when no table
     * was posted at all.
     *
     * @return list<array{name: string, amount: string, unit: string}>|null
     */
    public static function postedIngredients(Request $request): ?array
    {
        $names = $request->request->all('ing_name');
        if ($names === []) {
            return null;
        }
        $amounts = $request->request->all('ing_amount');
        $units = $request->request->all('ing_unit');

        $rows = [];
        foreach ($names as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $rows[] = [
                'name'   => mb_substr($name, 0, self::NAME_MAX_LENGTH),
                'amount' => mb_substr(trim((string) ($amounts[$i] ?? '')), 0, self::AMOUNT_MAX_LENGTH),
                'unit'   => mb_substr(trim((string) ($units[$i] ?? '')), 0, self::AMOUNT_MAX_LENGTH),
            ];
        }

        return $rows;
    }

    /**
     * The context for the next analysis: the draft's context so far, plus this
     * round's answered questions, plus the posted ingredient table as a
     * correction, but only when it differs from the draft's own list.
     *
     * @param list<array<string, mixed>>                              $context     the draft's context so far
     * @param array<int, string>                                       $questions   this round's questions
     * @param list<array{name: string, amount: string, unit: string}> $ingredients the draft's ingredients
     *
     * @return list<array<string, mixed>>
     */
    public static function nextContext(array $context, array $questions, array $ingredients, Request $request): array
    {
        $answers = $request->request->all('answers');
        foreach (array_values($questions) as $i => $question) {
            $answer = trim((string) ($answers[$i] ?? ''));
            if ($answer !== '') {
                $context[] = ['question' => (string) $question, 'answer' => $answer];
            }
        }

        $corrected = self::postedIngredients($request);
        if ($corrected !== null && $corrected !== [] && $corrected !== $ingredients) {
            $context[] = ['corrected_ingredients' => $corrected];
        }

        return $context;
    }
}

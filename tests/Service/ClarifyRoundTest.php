<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ClarifyRound;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ClarifyRoundTest extends TestCase
{
    private const DRAFT_INGREDIENTS = [['name' => 'oats', 'amount' => '80', 'unit' => 'g']];

    public function testTheFirstAnalysisCountsAsARound(): void
    {
        self::assertTrue(ClarifyRound::canContinue(1));
        self::assertTrue(ClarifyRound::canContinue(ClarifyRound::MAX_ROUNDS - 1));
        self::assertFalse(ClarifyRound::canContinue(ClarifyRound::MAX_ROUNDS));
    }

    public function testNoTablePostedIsNullNotEmpty(): void
    {
        self::assertNull(ClarifyRound::postedIngredients(new Request()));
    }

    public function testThePostedTableIsTrimmedBoundedAndSkipsBlankNames(): void
    {
        $rows = ClarifyRound::postedIngredients($this->post([
            'ing_name'   => ['  oats ', '   ', str_repeat('n', 250)],
            'ing_amount' => [' 80 ', '1', str_repeat('7', 60)],
            'ing_unit'   => ['g', 'cup', str_repeat('u', 60)],
        ]));

        self::assertSame([
            ['name' => 'oats', 'amount' => '80', 'unit' => 'g'],
            ['name' => str_repeat('n', 200), 'amount' => str_repeat('7', 50), 'unit' => str_repeat('u', 50)],
        ], $rows);
    }

    public function testAnsweredQuestionsJoinTheContextPairedWithTheirQuestion(): void
    {
        $context = ClarifyRound::nextContext(
            [['question' => 'earlier?', 'answer' => 'yes']],
            ['How much milk?', 'Any sugar?'],
            self::DRAFT_INGREDIENTS,
            $this->post(['answers' => ['A splash', '   ']]),
        );

        self::assertSame([
            ['question' => 'earlier?', 'answer' => 'yes'],
            ['question' => 'How much milk?', 'answer' => 'A splash'],
        ], $context);
    }

    public function testAnUnchangedTableIsNotACorrection(): void
    {
        $context = ClarifyRound::nextContext([], [], self::DRAFT_INGREDIENTS, $this->post([
            'ing_name' => ['oats'], 'ing_amount' => ['80'], 'ing_unit' => ['g'],
        ]));

        self::assertSame([], $context);
    }

    public function testAChangedTableIsACorrection(): void
    {
        $context = ClarifyRound::nextContext([], [], self::DRAFT_INGREDIENTS, $this->post([
            'ing_name' => ['steel-cut oats'], 'ing_amount' => ['80'], 'ing_unit' => ['g'],
        ]));

        self::assertSame(
            [['corrected_ingredients' => [['name' => 'steel-cut oats', 'amount' => '80', 'unit' => 'g']]]],
            $context,
        );
    }

    public function testATableEmptiedOfNamesIsNotACorrection(): void
    {
        $context = ClarifyRound::nextContext([], [], self::DRAFT_INGREDIENTS, $this->post([
            'ing_name' => ['  '], 'ing_amount' => ['80'], 'ing_unit' => ['g'],
        ]));

        self::assertSame([], $context);
    }

    /** @param array<string, mixed> $fields */
    private function post(array $fields): Request
    {
        return Request::create('/meal/clarify', 'POST', $fields);
    }
}

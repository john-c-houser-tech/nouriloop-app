<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\IngredientList;
use PHPUnit\Framework\TestCase;

final class IngredientListTest extends TestCase
{
    public function testNormalisesEachIngredientToStrings(): void
    {
        self::assertSame(
            [
                ['name' => 'oats', 'amount' => '80', 'unit' => 'g'],
                ['name' => 'salt', 'amount' => '', 'unit' => ''],
            ],
            IngredientList::fromClaudeJson([
                ['name' => 'oats', 'amount' => 80, 'unit' => 'g'],
                ['name' => 'salt'],
            ]),
        );
    }

    public function testDropsAnythingThatIsNotAnIngredientObject(): void
    {
        self::assertSame([], IngredientList::fromClaudeJson('oats, milk'));
        self::assertSame([], IngredientList::fromClaudeJson(null));
        self::assertSame(
            [['name' => 'milk', 'amount' => '', 'unit' => '']],
            IngredientList::fromClaudeJson(['oats', ['name' => 'milk']]),
        );
    }
}

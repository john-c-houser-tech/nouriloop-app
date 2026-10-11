<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PortionScaler;
use PHPUnit\Framework\TestCase;

final class PortionScalerTest extends TestCase
{
    private PortionScaler $scaler;

    protected function setUp(): void
    {
        $this->scaler = new PortionScaler();
    }

    public function testNormalizeKeepsPositiveValues(): void
    {
        self::assertSame(2.5, $this->scaler->normalize('2.5'));
        self::assertSame(0.5, $this->scaler->normalize(0.5));
    }

    public function testNormalizeFallsBackToOneForNonPositiveOrEmpty(): void
    {
        self::assertSame(1.0, $this->scaler->normalize('0'));
        self::assertSame(1.0, $this->scaler->normalize(-3));
        self::assertSame(1.0, $this->scaler->normalize(''));
        self::assertSame(1.0, $this->scaler->normalize('not a number'));
    }

    public function testNormalizeRejectsNonFiniteAndCapsHugeValues(): void
    {
        self::assertSame(1.0, $this->scaler->normalize('1e999'), 'INF falls back, never reaches nutrition');
        self::assertSame(1.0, $this->scaler->normalize(NAN));
        self::assertSame(PortionScaler::MAX_PORTIONS, $this->scaler->normalize('5000'));
        self::assertSame(100.0, $this->scaler->normalize('100'));
    }

    public function testScaleIngredientsIsIdentityAtOne(): void
    {
        $ingredients = [
            ['name' => 'white rice', 'amount' => '150', 'unit' => 'g'],
        ];

        self::assertSame($ingredients, $this->scaler->scaleIngredients($ingredients, 1.0));
    }

    public function testScaleIngredientsScalesNumericAmounts(): void
    {
        $ingredients = [
            ['name' => 'grilled chicken breast', 'amount' => '150', 'unit' => 'g'],
            ['name' => 'olive oil', 'amount' => '1.5', 'unit' => 'tbsp'],
        ];

        $scaled = $this->scaler->scaleIngredients($ingredients, 2.0);

        self::assertSame('300', $scaled[0]['amount']);
        self::assertSame('3', $scaled[1]['amount']);
    }

    public function testScaleIngredientsRoundsToTwoDecimals(): void
    {
        $ingredients = [
            ['name' => 'peanut butter', 'amount' => '1', 'unit' => 'tbsp'],
        ];

        $scaled = $this->scaler->scaleIngredients($ingredients, 0.333);

        self::assertSame('0.33', $scaled[0]['amount']);
    }

    public function testScaleIngredientsLeavesNonNumericAmountsUntouched(): void
    {
        $ingredients = [
            ['name' => 'salt', 'amount' => 'a pinch', 'unit' => ''],
            ['name' => 'water', 'amount' => '', 'unit' => 'cup'],
        ];

        $scaled = $this->scaler->scaleIngredients($ingredients, 3.0);

        self::assertSame('a pinch', $scaled[0]['amount']);
        self::assertSame('', $scaled[1]['amount']);
    }
}

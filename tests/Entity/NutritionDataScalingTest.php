<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\NutritionData;
use PHPUnit\Framework\TestCase;

final class NutritionDataScalingTest extends TestCase
{
    public function testFromArrayScalesNutrientValues(): void
    {
        $data = [
            'calories'      => 500.0,
            'protein'       => 30.0,
            'carbohydrates' => 60.0,
            'fat'           => 20.0,
        ];

        $scaled = NutritionData::fromArray($data, 1.5);

        self::assertSame(750.0, $scaled->getCalories());
        self::assertSame(45.0, $scaled->getProtein());
        self::assertSame(90.0, $scaled->getCarbohydrates());
        self::assertSame(30.0, $scaled->getFat());
    }

    public function testFromArrayPreservesNullNutrients(): void
    {
        $data = [
            'calories' => 500.0,
            'iron'     => null,
        ];

        $scaled = NutritionData::fromArray($data, 2.0);

        self::assertSame(1000.0, $scaled->getCalories());
        self::assertNull($scaled->getIron());
    }

    public function testConfidenceScoreIsNotScaled(): void
    {
        $data = [
            'calories'        => 400.0,
            'confidenceScore' => 0.8,
        ];

        $scaled = NutritionData::fromArray($data, 3.0);

        self::assertSame(1200.0, $scaled->getCalories());
        // Uncertainty is inherited as-is — a bigger portion is no more certain.
        self::assertSame(0.8, $scaled->getConfidenceScore());
    }

    public function testToArrayFromArrayRoundTripIsIdentityAtScaleOne(): void
    {
        $original = new NutritionData();
        $original->setCalories(420.0);
        $original->setProtein(12.5);
        $original->setSodium(800.0);
        $original->setConfidenceScore(0.65);

        $restored = NutritionData::fromArray($original->toArray());

        self::assertEquals($original->toArray(), $restored->toArray());
    }
}

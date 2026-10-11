<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\NutritionData;
use PHPUnit\Framework\TestCase;

final class NutritionDataClaudeJsonTest extends TestCase
{
    /** Every nutrient field and the snake_case key Claude's reply uses for it. */
    private const REPLY_KEYS = [
        'calories' => 'calories', 'protein' => 'protein', 'carbohydrates' => 'carbohydrates',
        'fat' => 'fat', 'saturatedFat' => 'saturated_fat', 'fiber' => 'fiber', 'sugar' => 'sugar',
        'vitaminA' => 'vitamin_a', 'vitaminB1' => 'vitamin_b1', 'vitaminB2' => 'vitamin_b2',
        'vitaminB3' => 'vitamin_b3', 'vitaminB6' => 'vitamin_b6', 'vitaminB9' => 'vitamin_b9',
        'vitaminB12' => 'vitamin_b12', 'vitaminC' => 'vitamin_c', 'vitaminD' => 'vitamin_d',
        'vitaminE' => 'vitamin_e', 'vitaminK' => 'vitamin_k', 'calcium' => 'calcium', 'iron' => 'iron',
        'magnesium' => 'magnesium', 'phosphorus' => 'phosphorus', 'potassium' => 'potassium',
        'sodium' => 'sodium', 'zinc' => 'zinc', 'selenium' => 'selenium',
    ];

    public function testEveryNutrientIsReadFromItsReplyKey(): void
    {
        $reply = [];
        $i = 1;
        foreach (self::REPLY_KEYS as $replyKey) {
            $reply[$replyKey] = $i++;
        }

        $nutrients = NutritionData::nutrientsFromClaudeJson($reply);

        $i = 1;
        foreach (array_keys(self::REPLY_KEYS) as $field) {
            self::assertSame((float) $i++, $nutrients[$field], $field);
        }
        self::assertCount(count(self::REPLY_KEYS), $nutrients);
    }

    public function testNullAndNonNumericValuesAreLeftOut(): void
    {
        $nutrients = NutritionData::nutrientsFromClaudeJson([
            'calories' => '~20',
            'protein' => 'unknown',
            'fat' => null,
            'fiber' => '3.5',
            'description' => 'not a nutrient',
        ]);

        self::assertSame(['fiber' => 3.5], $nutrients);
    }

    public function testFromClaudeJsonBuildsTheRowWithConfidence(): void
    {
        $row = NutritionData::fromClaudeJson([
            'calories' => 410,
            'vitamin_b12' => 2.4,
            'sodium' => 'a lot',
            'confidence' => 0.8,
        ]);

        self::assertSame(410.0, $row->getCalories());
        self::assertSame(2.4, $row->getVitaminB12());
        self::assertNull($row->getSodium());
        self::assertNull($row->getProtein());
        self::assertSame(0.8, $row->getConfidenceScore());
    }

    public function testNonNumericConfidenceIsNull(): void
    {
        self::assertNull(NutritionData::fromClaudeJson(['confidence' => 'high'])->getConfidenceScore());
    }
}

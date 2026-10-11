<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\NutritionData;
use App\Service\NutritionDiff;
use PHPUnit\Framework\TestCase;

/**
 * The before/after comparison behind the meal-history plan's §4 rule (a
 * re-analysis is never applied without showing what it would change). Pure, so
 * it's tested without a controller, a session, or a Claude call.
 */
final class NutritionDiffTest extends TestCase
{
    private NutritionDiff $diff;

    protected function setUp(): void
    {
        $this->diff = new NutritionDiff();
    }

    public function testRowsCarryBeforeAfterDeltaAndDirection(): void
    {
        $rows = $this->indexed($this->diff->rows(
            NutritionData::fromArray(['calories' => 400.0, 'protein' => 30.0, 'fiber' => 8.0]),
            ['calories' => 500.0, 'protein' => 20.0, 'fiber' => 8.0],
        ));

        self::assertSame(100.0, $rows['calories']->delta());
        self::assertSame('up', $rows['calories']->direction());
        self::assertSame(25.0, $rows['calories']->percentChange());

        self::assertSame(-10.0, $rows['protein']->delta());
        self::assertSame('down', $rows['protein']->direction());

        // Unchanged nutrients are KEPT: the table is a full statement of what
        // applying would do, not a highlight reel.
        self::assertArrayHasKey('fiber', $rows);
        self::assertSame('same', $rows['fiber']->direction());
        self::assertFalse($rows['fiber']->changed());
    }

    public function testNutrientNeitherSideHasIsDropped(): void
    {
        $rows = $this->indexed($this->diff->rows(
            NutritionData::fromArray(['calories' => 400.0]),
            ['calories' => 400.0],
        ));

        self::assertArrayHasKey('calories', $rows);
        self::assertArrayNotHasKey('selenium', $rows, 'an all-null row tells the user nothing');
    }

    public function testAddedAndRemovedEstimatesAreDistinctFromUpAndDown(): void
    {
        $rows = $this->indexed($this->diff->rows(
            NutritionData::fromArray(['calories' => 400.0, 'iron' => 3.0, 'zinc' => null]),
            ['calories' => 400.0, 'iron' => null, 'zinc' => 2.0],
        ));

        // The new estimate can't put a number on iron any more, and applyArray
        // will null it — so the diff must say that, not report a fall to zero.
        self::assertSame('removed', $rows['iron']->direction());
        self::assertNull($rows['iron']->delta());
        self::assertNull($rows['iron']->percentChange());

        self::assertSame('added', $rows['zinc']->direction());
        self::assertNull($rows['zinc']->delta());
    }

    public function testPercentChangeIsNullFromZeroRatherThanInfinite(): void
    {
        $rows = $this->indexed($this->diff->rows(
            NutritionData::fromArray(['calories' => 0.0]),
            ['calories' => 250.0],
        ));

        self::assertSame(250.0, $rows['calories']->delta());
        self::assertNull($rows['calories']->percentChange(), 'any rise from zero is not "+100%"');
    }

    public function testAMealWithNoStoredNutritionIsAllAdditions(): void
    {
        $rows = $this->indexed($this->diff->rows(null, ['calories' => 300.0, 'protein' => 12.0]));

        self::assertCount(2, $rows);
        self::assertSame('added', $rows['calories']->direction());
        self::assertSame('added', $rows['protein']->direction());
        self::assertTrue($this->diff->hasChanges(array_values($rows)));
    }

    public function testHasChangesIsFalseWhenTheReanalysisLandedOnTheSameNumbers(): void
    {
        // Worth reporting explicitly: the call still cost an allowance slot, so
        // the review screen says so rather than showing an empty-looking offer.
        $rows = $this->diff->rows(
            NutritionData::fromArray(['calories' => 400.0, 'protein' => 30.0]),
            ['calories' => 400.0, 'protein' => 30.0],
        );

        self::assertNotSame([], $rows);
        self::assertFalse($this->diff->hasChanges($rows));
    }

    public function testRowsAreOrderedMacrosFirstAndCarryUnits(): void
    {
        $rows = $this->diff->rows(
            null,
            array_fill_keys(array_keys(NutritionDiff::ROWS), 1.0),
        );

        self::assertSame(
            ['calories', 'protein', 'carbohydrates', 'fat'],
            array_map(static fn($r) => $r->field, array_slice($rows, 0, 4)),
        );
        self::assertCount(count(NutritionDiff::ROWS), $rows);
        self::assertSame('kcal', $rows[0]->unit);
        self::assertSame('nutrient_full.calories', $rows[0]->label());
    }

    /**
     * Every comparable field must have a label key, or the review table renders
     * a raw translation key at the user (phosphorus had none until Phase 3).
     */
    public function testEveryComparedFieldHasALabelInTheEnglishCatalog(): void
    {
        $catalog = (array) \Symfony\Component\Yaml\Yaml::parseFile(
            __DIR__ . '/../../translations/messages+intl-icu.en.yaml',
        );

        foreach (array_keys(NutritionDiff::ROWS) as $field) {
            self::assertArrayHasKey(
                $field,
                $catalog['nutrient_full'] ?? [],
                "nutrient_full.{$field} is missing from the en catalog",
            );
        }
    }

    /**
     * @param list<\App\Dto\NutritionDiffRow> $rows
     *
     * @return array<string, \App\Dto\NutritionDiffRow>
     */
    private function indexed(array $rows): array
    {
        $byField = [];
        foreach ($rows as $row) {
            $byField[$row->field] = $row;
        }

        return $byField;
    }
}

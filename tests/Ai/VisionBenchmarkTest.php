<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\VisionBenchmark;
use App\Dto\AnalysisResult;
use App\Entity\NutritionData;
use PHPUnit\Framework\TestCase;

final class VisionBenchmarkTest extends TestCase
{
    private const PIZZA = 0;
    private const PROTEIN_BAR = 1;
    private const BLUEBERRIES = 3;

    public function testTheReferenceValuesPass(): void
    {
        foreach (VisionBenchmark::CASES as $i => $case) {
            self::assertTrue(VisionBenchmark::check($i, $this->analysis($case['expected']))->passed, $case['name']);
        }
    }

    public function testALabelCaseAllowsTenPercentAndFailsBeyondIt(): void
    {
        $pizza = VisionBenchmark::CASES[self::PIZZA]['expected'];

        self::assertTrue(VisionBenchmark::check(self::PIZZA, $this->analysis(['calories' => 363] + $pizza))->passed);

        $result = VisionBenchmark::check(self::PIZZA, $this->analysis(['calories' => 980] + $pizza));
        self::assertFalse($result->passed, 'the whole pizza is not one serving');
        self::assertSame([['nutrient' => 'calories', 'expected' => 330.0, 'got' => 980.0]], $result->misses);
    }

    public function testSmallLabelValuesHaveAnAbsoluteFloor(): void
    {
        // The bar's 1 g fiber: 2 g is 100 % off but within the 1.5 g floor; 3 g is not.
        $bar = VisionBenchmark::CASES[self::PROTEIN_BAR]['expected'];

        self::assertTrue(VisionBenchmark::check(self::PROTEIN_BAR, $this->analysis(['fiber' => 2] + $bar))->passed);
        self::assertFalse(VisionBenchmark::check(self::PROTEIN_BAR, $this->analysis(['fiber' => 3] + $bar))->passed);
    }

    public function testEveryLabelNutrientUsesTheDocumentedTenPercent(): void
    {
        // Copilot catch on #244: fiber was 20 %, hidden by its floor on today's photos.
        foreach (VisionBenchmark::LABEL_TOLERANCE as $nutrient => [$relative]) {
            self::assertSame(0.10, $relative, $nutrient);
        }
    }

    public function testAnEstimateCaseIsJudgedOnCaloriesWithinFortyPercent(): void
    {
        $offOnlyOnProtein = $this->analysis(['calories' => 117, 'protein' => 9]);
        self::assertTrue(VisionBenchmark::check(self::BLUEBERRIES, $offOnlyOnProtein)->passed);
        self::assertFalse(VisionBenchmark::check(self::BLUEBERRIES, $this->analysis(['calories' => 120]))->passed);
        self::assertFalse(VisionBenchmark::check(self::BLUEBERRIES, $this->analysis(['calories' => 49]))->passed);
    }

    public function testANutrientTheModelLeftBlankIsAMiss(): void
    {
        $pizza = VisionBenchmark::CASES[self::PIZZA]['expected'];
        unset($pizza['sodium']);

        $result = VisionBenchmark::check(self::PIZZA, $this->analysis($pizza));
        self::assertFalse($result->passed);
        self::assertSame([['nutrient' => 'sodium', 'expected' => 780.0, 'got' => null]], $result->misses);
    }

    public function testTheFixturesExistAndCarryNoMetadata(): void
    {
        // They ship publicly (Plan Q9): a re-shot photo must not bring GPS or camera data along.
        $dir = \dirname(__DIR__, 2) . '/' . VisionBenchmark::DIR;
        foreach (VisionBenchmark::CASES as $case) {
            $bytes = (string) file_get_contents($dir . '/' . $case['file']);
            self::assertNotSame('', $bytes, $case['file']);
            foreach (['Exif', 'GPS', 'iPhone', 'Apple', 'http://ns.adobe.com/xap'] as $marker) {
                self::assertStringNotContainsString($marker, $bytes, $case['file'] . ' contains ' . $marker);
            }
            self::assertLessThan(1_000_000, \strlen($bytes), $case['file'] . ' should stay small');
        }
    }

    /** @param array<string, float|int> $values */
    private function analysis(array $values): AnalysisResult
    {
        return new AnalysisResult(NutritionData::fromArray($values), 0.9, '', []);
    }
}

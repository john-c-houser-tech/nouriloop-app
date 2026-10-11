<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MealEntry;
use App\Service\NauseaTriggerService;
use PHPUnit\Framework\TestCase;

final class NauseaTriggerServiceTest extends TestCase
{
    private NauseaTriggerService $service;

    protected function setUp(): void
    {
        $this->service = new NauseaTriggerService();
    }

    /** @param array<string, list<string>> $foodsByDate */
    private function foods(array $foodsByDate): array
    {
        $out = [];
        foreach ($foodsByDate as $date => $names) {
            foreach ($names as $name) {
                $out[$date][$name] = true;
            }
        }

        return $out;
    }

    public function testInsufficientDaysReturnsNoTriggers(): void
    {
        $result = $this->service->summarize(
            $this->foods(['2026-06-01' => ['eggs'], '2026-06-02' => ['eggs']]),
            ['2026-06-01' => 5, '2026-06-02' => 6],
        );

        self::assertTrue($result['insufficient']);
        self::assertSame(2, $result['evaluatedDays']);
        self::assertSame([], $result['triggers']);
        self::assertSame(5.5, $result['overallAvg']);
    }

    public function testRanksFoodWithHigherNauseaWhenEaten(): void
    {
        // 'fried chicken' eaten on the 3 high-nausea days; 'oatmeal' on the calm days.
        $foods = $this->foods([
            '2026-06-01' => ['fried chicken', 'soda'],
            '2026-06-02' => ['fried chicken', 'rice'],
            '2026-06-03' => ['fried chicken', 'salad'],
            '2026-06-04' => ['oatmeal'],
            '2026-06-05' => ['oatmeal'],
            '2026-06-06' => ['oatmeal'],
        ]);
        $nausea = [
            '2026-06-01' => 8,
            '2026-06-02' => 7,
            '2026-06-03' => 9,
            '2026-06-04' => 1,
            '2026-06-05' => 2,
            '2026-06-06' => 0,
        ];

        $result = $this->service->summarize($foods, $nausea);

        self::assertFalse($result['insufficient']);
        self::assertNotEmpty($result['triggers']);
        // Highest-lift trigger is the food eaten on all the bad days.
        self::assertSame('fried chicken', $result['triggers'][0]['food']);
        self::assertSame(3, $result['triggers'][0]['daysEaten']);
        self::assertSame(8.0, $result['triggers'][0]['avgWhenEaten']);
        self::assertSame(1.0, $result['triggers'][0]['avgWhenNot']);
        self::assertSame(7.0, $result['triggers'][0]['lift']);

        // 'oatmeal' is eaten only on calm days → negative lift → not a trigger.
        $foodsFlagged = array_column($result['triggers'], 'food');
        self::assertNotContains('oatmeal', $foodsFlagged);
    }

    public function testFoodEatenEveryDayHasNoContrastAndIsSkipped(): void
    {
        // 'coffee' every day → no not-eaten group → excluded even though nausea varies.
        $foods = $this->foods([
            '2026-06-01' => ['coffee'],
            '2026-06-02' => ['coffee'],
            '2026-06-03' => ['coffee'],
            '2026-06-04' => ['coffee'],
        ]);
        $nausea = ['2026-06-01' => 8, '2026-06-02' => 7, '2026-06-03' => 2, '2026-06-04' => 1];

        $result = $this->service->summarize($foods, $nausea);

        self::assertSame([], $result['triggers']);
    }

    public function testFoodBelowMinDaysIsSkipped(): void
    {
        // 'shrimp' eaten only twice (< MIN_DAYS_PER_FOOD = 3), so not ranked.
        $foods = $this->foods([
            '2026-06-01' => ['shrimp', 'toast'],
            '2026-06-02' => ['shrimp', 'toast'],
            '2026-06-03' => ['toast'],
            '2026-06-04' => ['toast'],
        ]);
        $nausea = ['2026-06-01' => 9, '2026-06-02' => 8, '2026-06-03' => 1, '2026-06-04' => 0];

        $result = $this->service->summarize($foods, $nausea);

        self::assertNotContains('shrimp', array_column($result['triggers'], 'food'));
    }

    public function testAnalyzeNormalizesIngredientNamesAndDedupesPerDay(): void
    {
        // Two meals same day both list chicken with different casing → counts once.
        $meals = [
            $this->meal('2026-06-01', ['Fried Chicken', 'Soda']),
            $this->meal('2026-06-01', ['fried chicken']),
            $this->meal('2026-06-02', ['fried chicken']),
            $this->meal('2026-06-03', ['FRIED CHICKEN']),
            $this->meal('2026-06-04', ['oatmeal']),
            $this->meal('2026-06-05', ['oatmeal']),
        ];
        $nausea = [
            '2026-06-01' => 8, '2026-06-02' => 7, '2026-06-03' => 9,
            '2026-06-04' => 1, '2026-06-05' => 0,
        ];

        $result = $this->service->analyze($meals, $nausea);

        self::assertSame('fried chicken', $result['triggers'][0]['food']);
        self::assertSame(3, $result['triggers'][0]['daysEaten']);
    }

    /** @param list<string> $ingredientNames */
    private function meal(string $date, array $ingredientNames): MealEntry
    {
        $ingredients = array_map(
            static fn (string $n): array => ['name' => $n, 'amount' => '1', 'unit' => 'serving'],
            $ingredientNames,
        );

        return (new MealEntry())
            ->setLoggedAt(new \DateTimeImmutable($date . ' 12:00:00'))
            ->setIngredients($ingredients);
    }
}

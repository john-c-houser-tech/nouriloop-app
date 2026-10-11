<?php

declare(strict_types=1);

namespace App\Tests\Intake;

use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Intake\DailyIntake;
use App\Intake\IntakeDay;
use App\Repository\NutritionDataRepository;
use App\Repository\SupplementRepository;
use App\Service\UserTime;
use PHPUnit\Framework\TestCase;

final class DailyIntakeTest extends TestCase
{
    use StubsSupplements;

    private const SHAKE = ['protein' => 25.0, 'calories' => 120.0];

    public function testADayExistsOnlyWhereAMealWasLogged(): void
    {
        $days = $this->intake([
            '2026-06-10' => ['protein' => 40.0, 'calories' => 600.0],
            '2026-06-12' => ['protein' => 30.0, 'calories' => 500.0],
        ], self::SHAKE)->days($this->user(), $this->from(), $this->to());

        self::assertSame(['2026-06-10', '2026-06-12'], array_keys($days), 'no supplement-only day on the 11th');
    }

    public function testMealDaysCountTheActiveSupplementsOnTopAndKeepThemApart(): void
    {
        $day = $this->intake(['2026-06-10' => ['protein' => 40.0, 'fiber' => null]], self::SHAKE)
            ->days($this->user(), $this->from(), $this->to())['2026-06-10'];

        self::assertSame(40.0, $day->food['protein']);
        self::assertSame(self::SHAKE, $day->supplements);
        self::assertSame(65.0, $day->total('protein'));
        self::assertSame(120.0, $day->total('calories'), 'a nutrient only the supplements carry');
        self::assertNull($day->total('fiber'), 'neither food nor supplements reported it');
    }

    public function testTheAverageIsFoodOverMealDaysWithTheSupplementsOnTop(): void
    {
        $average = $this->intake([
            '2026-06-10' => ['protein' => 40.0],
            '2026-06-12' => ['protein' => 60.0],
        ], self::SHAKE)->average($this->user(), $this->from(), $this->to());

        self::assertNotNull($average);
        self::assertSame(2, $average->mealDays);
        self::assertSame(50.0, $average->food['protein']);
        self::assertSame(self::SHAKE, $average->supplements);
        self::assertSame(75.0, $average->totals()['protein']);
        self::assertArrayHasKey('phosphorus', $average->totals(), 'every nutrient is present');
    }

    public function testWithNoMealDaysThereIsNoAverageEvenWithSupplements(): void
    {
        self::assertNull($this->intake([], self::SHAKE)->average($this->user(), $this->from(), $this->to()));
    }

    public function testForMealsSumsTheMealsAlreadyLoaded(): void
    {
        $days = $this->intake([], self::SHAKE)->forMeals($this->user(), [
            $this->meal('2026-06-10 08:00', 20.0),
            $this->meal('2026-06-10 19:00', 15.5),
            (new MealEntry())->setLoggedAt(new \DateTimeImmutable('2026-06-10 12:00')),
        ]);

        self::assertSame(['2026-06-10'], array_keys($days));
        self::assertSame(35.5, $days['2026-06-10']->food['protein']);
        self::assertSame(60.5, $days['2026-06-10']->total('protein'));
    }

    public function testNoMealsMeansNoIntakeEvenWithSupplements(): void
    {
        self::assertSame([], $this->intake([], self::SHAKE)->forMeals($this->user(), []));
        self::assertSame(
            [],
            $this->intake([], self::SHAKE)->forMeals($this->user(), [new MealEntry()]),
            'a meal without nutrition is not a meal day',
        );
    }

    public function testASupplementCountsOnlyFromTheDayItWasAdded(): void
    {
        $nutrition = $this->createStub(NutritionDataRepository::class);
        $nutrition->method('dailyNutrientTotals')->willReturn([
            '2026-06-10' => ['protein' => 40.0] + array_fill_keys(NutritionData::FIELDS, null),
            '2026-06-12' => ['protein' => 60.0] + array_fill_keys(NutritionData::FIELDS, null),
        ]);
        $supplements = $this->createStub(SupplementRepository::class);
        $supplements->method('findActive')->willReturn(self::takenSince(['protein' => 25.0], '2026-06-11'));
        $intake = new DailyIntake($nutrition, $supplements, new UserTime());

        $days = $intake->days($this->user(), $this->from(), $this->to());
        self::assertSame(40.0, $days['2026-06-10']->total('protein'), 'not yet taken on the 10th');
        self::assertSame(85.0, $days['2026-06-12']->total('protein'));

        // Counted on one of the two meal days, so half its amount per day on average.
        $average = $intake->average($this->user(), $this->from(), $this->to());
        self::assertSame(12.5, $average->supplements['protein']);
        self::assertSame(62.5, $average->totals()['protein']);
    }

    public function testATotalIsNullOnlyWhenNothingReportedIt(): void
    {
        $day = new IntakeDay('2026-06-10', ['sugar' => null, 'fat' => 0.0], ['sodium' => 5.0]);

        self::assertNull($day->total('sugar'));
        self::assertSame(0.0, $day->total('fat'));
        self::assertSame(5.0, $day->total('sodium'));
    }

    /**
     * @param array<string, array<string, float|null>> $days
     * @param array<string, float>                     $supplements
     */
    private function intake(array $days, array $supplements = []): DailyIntake
    {
        $nutrition = $this->createStub(NutritionDataRepository::class);
        $nutrition->method('dailyNutrientTotals')->willReturn(array_map(
            static fn (array $food): array => $food + array_fill_keys(NutritionData::FIELDS, null),
            $days,
        ));
        $supplementRepo = $this->createStub(SupplementRepository::class);
        $supplementRepo->method('findActive')->willReturn(self::takenSince($supplements));

        return new DailyIntake($nutrition, $supplementRepo, new UserTime());
    }

    private function meal(string $at, float $protein): MealEntry
    {
        return (new MealEntry())
            ->setLoggedAt(new \DateTimeImmutable($at))
            ->setNutritionData((new NutritionData())->setProtein($protein));
    }

    private function user(): User
    {
        return new User();
    }

    private function from(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-01');
    }

    private function to(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-01');
    }
}

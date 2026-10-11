<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Intake\DailyIntake;
use App\Repository\NutritionDataRepository;
use App\Repository\SupplementRepository;
use App\Repository\HealthMetricRepository;
use App\Service\Glp1ProteinService;
use App\Tests\Intake\StubsSupplements;
use App\Service\UserTime;
use PHPUnit\Framework\TestCase;

final class Glp1ProteinServiceTest extends TestCase
{
    use StubsSupplements;

    /** @param array<string, float> $supplements the active supplements' daily amounts */
    private function service(?float $latestWeightKg, array $supplements = []): Glp1ProteinService
    {
        $repo = $this->createStub(HealthMetricRepository::class);
        $repo->method('findLatestValue')->willReturn($latestWeightKg);
        $supplementRepo = $this->createStub(SupplementRepository::class);
        $supplementRepo->method('findActive')->willReturn(self::takenSince($supplements));

        return new Glp1ProteinService($repo, new DailyIntake(
            $this->createStub(NutritionDataRepository::class),
            $supplementRepo,
            new UserTime(),
        ));
    }

    /** @param list<float|null> $proteins one meal per value; null = meal with no nutrition row */
    private function meals(array $proteins): array
    {
        $meals = [];
        foreach ($proteins as $p) {
            $meal = (new MealEntry())->setLoggedAt(new \DateTimeImmutable('2026-06-10 12:00:00'));
            if ($p !== null) {
                $meal->setNutritionData((new NutritionData())->setProtein($p));
            }
            $meals[] = $meal;
        }

        return $meals;
    }

    public function testTargetScalesToBodyWeight(): void
    {
        // 80 kg × 1.4 = 112 g target.
        $summary = $this->service(80.0)->summary(new User(), $this->meals([30.0, 25.0]));

        self::assertSame(112.0, $summary['target']);
        self::assertTrue($summary['basedOnWeight']);
        self::assertSame(55.0, $summary['consumed']);
        self::assertSame(57.0, $summary['remaining']);
        self::assertSame(49, $summary['percent']); // 55 / 112 ≈ 49%
    }

    public function testTargetRespectsFloorForLowWeight(): void
    {
        // 30 kg × 1.4 = 42 g, below the 60 g floor.
        $summary = $this->service(30.0)->summary(new User(), []);

        self::assertSame(Glp1ProteinService::MIN_TARGET_G, $summary['target']);
        self::assertTrue($summary['basedOnWeight']);
    }

    public function testFallsBackToDefaultWithNoWeight(): void
    {
        $summary = $this->service(null)->summary(new User(), $this->meals([40.0]));

        self::assertSame(Glp1ProteinService::DEFAULT_TARGET_G, $summary['target']);
        self::assertFalse($summary['basedOnWeight']);
    }

    public function testPercentCapsAndRemainingFloorsWhenOverTarget(): void
    {
        // No weight → 80 g target; consume 100 g.
        $summary = $this->service(null)->summary(new User(), $this->meals([60.0, 40.0]));

        self::assertSame(100.0, $summary['consumed']);
        self::assertSame(0.0, $summary['remaining']);
        self::assertSame(100, $summary['percent']);
    }

    public function testMealsWithoutNutritionAreIgnored(): void
    {
        $summary = $this->service(null)->summary(new User(), $this->meals([null, 20.0, null]));

        self::assertSame(20.0, $summary['consumed']);
    }

    public function testAProteinShakeCountsOnceAMealIsLogged(): void
    {
        // ADR 0002: the card ignored supplements until 2026-10-06.
        $shake = ['protein' => 25.0];

        $withMeals = $this->service(null, $shake)->summary(new User(), $this->meals([30.0, 25.0]));
        self::assertSame(80.0, $withMeals['consumed']);
        $noMeals = $this->service(null, $shake)->summary(new User(), []);
        self::assertSame(0.0, $noMeals['consumed'], 'no meals, no intake');
    }
}

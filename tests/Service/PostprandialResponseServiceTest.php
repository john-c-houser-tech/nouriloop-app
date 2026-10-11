<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Repository\HealthMetricRepository;
use App\Service\PostprandialResponseService;
use PHPUnit\Framework\TestCase;

final class PostprandialResponseServiceTest extends TestCase
{
    private const MEAL_AT = '2026-06-07 12:30:00';

    /**
     * @param list<array{recordedAt: string, value: string}> $rows
     */
    private function service(array $rows): PostprandialResponseService
    {
        $repo = $this->createStub(HealthMetricRepository::class);
        $repo->method('findGlucoseInWindow')->willReturn($rows);

        return new PostprandialResponseService($repo);
    }

    private function meal(): MealEntry
    {
        $meal = new MealEntry();
        $meal->setUser(new User());
        $meal->setLoggedAt(new \DateTimeImmutable(self::MEAL_AT));

        return $meal;
    }

    /**
     * Builds a window of 5-minute readings: one pre-meal baseline reading, then a
     * rise to a peak and a return, mirroring a real CGM curve.
     *
     * @param list<int> $postValues values at +0, +5, +10, … minutes
     * @return list<array{recordedAt: string, value: string}>
     */
    private function curve(int $baseline, array $postValues): array
    {
        $base   = new \DateTimeImmutable(self::MEAL_AT);
        $rows   = [[
            'recordedAt' => $base->modify('-15 minutes')->format('Y-m-d H:i:s'),
            'value'      => (string) $baseline,
        ]];
        foreach ($postValues as $i => $v) {
            $rows[] = [
                'recordedAt' => $base->modify('+' . ($i * 5) . ' minutes')->format('Y-m-d H:i:s'),
                'value'      => (string) $v,
            ];
        }

        return $rows;
    }

    public function testReturnsNullWhenNoReadingsInWindow(): void
    {
        self::assertNull($this->service([])->forMeal($this->meal()));
    }

    public function testComputesPeakDeltaAndTimeToPeak(): void
    {
        // baseline 100; post readings at +0,+5,…,+35 min, peaking 150 at +15 min,
        // back to baseline (≤105) at +30 min.
        $rows = $this->curve(100, [100, 120, 140, 150, 130, 110, 100, 100]);

        $r = $this->service($rows)->forMeal($this->meal());

        self::assertNotNull($r);
        self::assertTrue($r->hasCurve);
        self::assertTrue($r->hasBaseline);
        self::assertSame(100.0, $r->baseline);
        self::assertSame(150.0, $r->peak);
        self::assertSame(15, $r->timeToPeakMin);
        self::assertSame(50.0, $r->delta);
        self::assertSame(30, $r->returnToBaselineMin);
        self::assertGreaterThan(0, $r->iauc);
    }

    public function testTooFewReadingsHasNoCurveButStillReturns(): void
    {
        $rows = $this->curve(100, [110, 130]); // 2 post readings < MIN_READINGS_FOR_CURVE

        $r = $this->service($rows)->forMeal($this->meal());

        self::assertNotNull($r);
        self::assertFalse($r->hasCurve);
        self::assertSame('low', $r->confidence);
    }

    public function testFlatGlucoseHasZeroIaucAndNoExcursion(): void
    {
        $rows = $this->curve(95, [95, 95, 95, 95, 95, 95, 95]);

        $r = $this->service($rows)->forMeal($this->meal());

        self::assertNotNull($r);
        self::assertSame(0.0, $r->delta);
        self::assertSame(0.0, $r->iauc);
    }

    public function testReadingJustBeforeMealCountsAsBaselineNotPostMeal(): void
    {
        // A reading 10 seconds before the meal must be treated as pre-meal baseline,
        // not rounded up to minute 0 and counted as the response start.
        $base = new \DateTimeImmutable(self::MEAL_AT);
        $rows = [[
            'recordedAt' => $base->modify('-10 seconds')->format('Y-m-d H:i:s'),
            'value'      => '90',
        ]];
        foreach ([120, 140, 150, 130, 110, 100] as $i => $v) {
            $rows[] = [
                'recordedAt' => $base->modify('+' . ($i * 5) . ' minutes')->format('Y-m-d H:i:s'),
                'value'      => (string) $v,
            ];
        }

        $r = $this->service($rows)->forMeal($this->meal());

        self::assertNotNull($r);
        self::assertTrue($r->hasBaseline);          // the -10s reading is the baseline
        self::assertSame(90.0, $r->baseline);
        self::assertSame(150.0, $r->peak);
        self::assertSame(60.0, $r->delta);          // 150 − 90, not 150 − 120
    }

    public function testFallsBackToFirstReadingWhenNoPreMealBaseline(): void
    {
        // No pre-meal reading: all readings are at/after the meal.
        $base = new \DateTimeImmutable(self::MEAL_AT);
        $rows = [];
        foreach ([100, 120, 140, 130, 120, 110, 100] as $i => $v) {
            $rows[] = [
                'recordedAt' => $base->modify('+' . ($i * 5) . ' minutes')->format('Y-m-d H:i:s'),
                'value'      => (string) $v,
            ];
        }

        $r = $this->service($rows)->forMeal($this->meal());

        self::assertNotNull($r);
        self::assertFalse($r->hasBaseline);
        self::assertSame(100.0, $r->baseline);     // first at-meal reading
        self::assertNotSame('high', $r->confidence); // no true baseline → never "high"
    }

    /**
     * forMeals() makes ONE glucose query for the whole set and hands each meal
     * exactly the rows its own query would return — so every result is identical
     * to forMeal(), including a boundary reading shared by two overlapping
     * windows and a meal with no readings at all.
     */
    public function testForMealsMatchesForMealWithOneQuery(): void
    {
        $user = new User();
        // A dense 5-minute CGM feed across two days, wobbling so each window differs.
        $series = [];
        $t = new \DateTimeImmutable('2026-06-07 06:00:00');
        for ($i = 0; $t < new \DateTimeImmutable('2026-06-08 23:00:00'); $i++, $t = $t->modify('+5 minutes')) {
            $series[] = ['recordedAt' => $t->format('Y-m-d H:i:s'), 'value' => (string) (95 + ($i * 7) % 60)];
        }

        $calls = 0;
        $repo = $this->createStub(HealthMetricRepository::class);
        $repo->method('findGlucoseInWindow')->willReturnCallback(
            static function (User $u, \DateTimeImmutable $from, \DateTimeImmutable $to) use ($series, &$calls): array {
                $calls++;
                $f = $from->format('Y-m-d H:i:s');
                $l = $to->format('Y-m-d H:i:s');

                return array_values(array_filter(
                    $series,
                    static fn (array $r): bool => $r['recordedAt'] >= $f && $r['recordedAt'] <= $l,
                ));
            },
        );
        $service = new PostprandialResponseService($repo);

        $meals = [];
        // 08:00 and 10:00 overlap (the 10:00 window starts at 09:45), a meal the
        // next day sits far from the others, and the one on 06-10 has no
        // readings at all.
        foreach (['2026-06-07 08:00:00', '2026-06-07 10:00:00', '2026-06-08 12:30:00', '2026-06-10 12:00:00'] as $at) {
            $meal = new MealEntry();
            $meal->setUser($user);
            $meal->setLoggedAt(new \DateTimeImmutable($at));
            $meals[] = $meal;
        }

        $expected = array_map(static fn (MealEntry $m) => $service->forMeal($m), $meals);
        $calls = 0;
        $batched = $service->forMeals($meals);

        self::assertSame(1, $calls, 'one query for the whole set');
        self::assertEquals($expected, $batched);
        self::assertNotNull($batched[0]);
        self::assertNull($batched[3]);
    }
}

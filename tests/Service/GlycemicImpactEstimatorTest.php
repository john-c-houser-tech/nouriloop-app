<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\PostprandialResponse;
use App\Entity\NutritionData;
use App\Service\GlycemicImpactEstimator;
use PHPUnit\Framework\TestCase;

final class GlycemicImpactEstimatorTest extends TestCase
{
    private GlycemicImpactEstimator $estimator;

    protected function setUp(): void
    {
        $this->estimator = new GlycemicImpactEstimator();
    }

    private function nutrition(
        ?float $carbs,
        float $fiber = 0.0,
        float $sugar = 0.0,
        float $fat = 0.0,
        float $protein = 0.0,
    ): NutritionData {
        $n = new NutritionData();
        $n->setCarbohydrates($carbs);
        $n->setFiber($fiber);
        $n->setSugar($sugar);
        $n->setFat($fat);
        $n->setProtein($protein);

        return $n;
    }

    private function measured(float $iauc): PostprandialResponse
    {
        return new PostprandialResponse(
            series: [],
            baseline: 90.0,
            peak: 120.0,
            timeToPeakMin: 45,
            delta: 30.0,
            iauc: $iauc,
            returnToBaselineMin: 90,
            readingCount: 20,
            hasBaseline: true,
            hasCurve: true,
            confidence: 'high',
        );
    }

    public function testReturnsNullWhenCarbsNull(): void
    {
        self::assertNull($this->estimator->estimate($this->nutrition(null), $this->measured(1000.0)));
    }

    public function testReturnsNullWhenIaucNull(): void
    {
        $r = new PostprandialResponse(
            series: [],
            baseline: 90.0,
            peak: null,
            timeToPeakMin: null,
            delta: null,
            iauc: null,
            returnToBaselineMin: null,
            readingCount: 3,
            hasBaseline: true,
            hasCurve: false,
            confidence: 'low',
        );
        self::assertNull($this->estimator->estimate($this->nutrition(50.0), $r));
    }

    public function testReturnsNullWhenAvailableCarbsBelowThreshold(): void
    {
        // fiber (10g) > carbs (8g) → available carbs = 0
        self::assertNull($this->estimator->estimate($this->nutrition(8.0, 10.0), $this->measured(500.0)));
    }

    public function testHighCarbMealProducesHighGL(): void
    {
        // 80g carbs, 5g fiber → 75g available; mostly sugar → high eGL
        $result = $this->estimator->estimate(
            $this->nutrition(80.0, 5.0, 60.0, 5.0, 10.0),
            $this->measured(3000.0),
        );

        self::assertNotNull($result);
        self::assertSame('high', $result->glCategory);
        self::assertGreaterThan(20.0, $result->estimatedGL);
    }

    public function testLowCarbHighFiberMealProducesLowGL(): void
    {
        // 18g carbs, 10g fiber → 8g available
        $result = $this->estimator->estimate(
            $this->nutrition(18.0, 10.0, 2.0, 15.0, 25.0),
            $this->measured(300.0),
        );

        self::assertNotNull($result);
        self::assertSame('low', $result->glCategory);
        self::assertLessThanOrEqual(10.0, $result->estimatedGL);
    }

    public function testAsExpectedBandWhenMeasuredEqualsExpected(): void
    {
        $n = $this->nutrition(50.0, 5.0, 15.0, 10.0, 20.0);
        // First pass: measure at an absurd value to extract expectedIAUC.
        $probe = $this->estimator->estimate($n, $this->measured(99999.0));
        self::assertNotNull($probe);

        // Now measure exactly at expectedIAUC — ratio = 1.0 → as_expected.
        $result = $this->estimator->estimate($n, $this->measured($probe->expectedIAUC));
        self::assertNotNull($result);
        self::assertSame('as_expected', $result->band);
        self::assertEqualsWithDelta(1.0, $result->ratio, 0.01);
    }

    public function testHigherBandWhenResponseIs140PercentOfExpected(): void
    {
        $n = $this->nutrition(50.0, 5.0, 10.0, 8.0, 20.0);
        $probe = $this->estimator->estimate($n, $this->measured(99999.0));
        self::assertNotNull($probe);

        $result = $this->estimator->estimate($n, $this->measured($probe->expectedIAUC * 1.4));
        self::assertNotNull($result);
        self::assertSame('higher', $result->band);
    }

    public function testMuchHigherBandWhenResponseIsTwoTimesExpected(): void
    {
        $n = $this->nutrition(50.0, 5.0, 10.0, 8.0, 20.0);
        $probe = $this->estimator->estimate($n, $this->measured(99999.0));
        self::assertNotNull($probe);

        $result = $this->estimator->estimate($n, $this->measured($probe->expectedIAUC * 2.0));
        self::assertNotNull($result);
        self::assertSame('much_higher', $result->band);
    }

    public function testFatDampingLowersExpectedIauc(): void
    {
        // Same carbs, higher fat → lower eGL → lower expectedIAUC.
        $lean  = $this->estimator->estimate($this->nutrition(50.0, 3.0, 10.0, 5.0, 15.0), $this->measured(1000.0));
        $fatty = $this->estimator->estimate($this->nutrition(50.0, 3.0, 10.0, 40.0, 15.0), $this->measured(1000.0));

        self::assertNotNull($lean);
        self::assertNotNull($fatty);
        self::assertGreaterThan($fatty->expectedIAUC, $lean->expectedIAUC);
    }

    public function testSugarFractionRaisesExpectedIauc(): void
    {
        // Same available carbs: all starch vs. all sugar → higher expected response.
        $starchy = $this->estimator->estimate($this->nutrition(50.0, 5.0, 0.0, 5.0, 10.0), $this->measured(1000.0));
        $sugary  = $this->estimator->estimate($this->nutrition(50.0, 5.0, 45.0, 5.0, 10.0), $this->measured(1000.0));

        self::assertNotNull($starchy);
        self::assertNotNull($sugary);
        self::assertGreaterThan($starchy->expectedIAUC, $sugary->expectedIAUC);
    }
}

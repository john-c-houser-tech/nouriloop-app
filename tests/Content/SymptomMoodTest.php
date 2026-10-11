<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\SymptomMood;
use PHPUnit\Framework\TestCase;

final class SymptomMoodTest extends TestCase
{
    public function testRampRunsWorstToBestAcrossElevenSteps(): void
    {
        self::assertCount(11, SymptomMood::RAMP);
        self::assertSame('😩', SymptomMood::RAMP[0]);
        self::assertSame('😐', SymptomMood::RAMP[5]);
        self::assertSame('😁', SymptomMood::RAMP[10]);
    }

    public function testHigherIsWorseSymptomFlipsGoodness(): void
    {
        // Joint pain: 0 = no pain = best, 10 = worst.
        self::assertSame('😁', SymptomMood::forLevel(0, false));
        self::assertSame('😩', SymptomMood::forLevel(10, false));
        self::assertSame('😐', SymptomMood::forLevel(5, false));
    }

    public function testHigherIsBetterSymptomKeepsGoodness(): void
    {
        // Energy: 0 = depleted = worst, 10 = best.
        self::assertSame('😩', SymptomMood::forLevel(0, true));
        self::assertSame('😁', SymptomMood::forLevel(10, true));
        self::assertSame('😐', SymptomMood::forLevel(5, true));
    }

    public function testMiddleDefaultIsNeutralEitherDirection(): void
    {
        self::assertSame('😐', SymptomMood::forLevel(5, true));
        self::assertSame('😐', SymptomMood::forLevel(5, false));
    }

    public function testOutOfRangeLevelsClamp(): void
    {
        self::assertSame('😁', SymptomMood::forLevel(99, true));
        self::assertSame('😩', SymptomMood::forLevel(-3, true));
    }
}

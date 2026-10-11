<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\WellnessDisclaimers;
use PHPUnit\Framework\TestCase;

final class WellnessDisclaimersTest extends TestCase
{
    public function testBothSetsHaveTwentyNonEmptyEntries(): void
    {
        foreach ([WellnessDisclaimers::LONG, WellnessDisclaimers::SHORT] as $length) {
            $set = WellnessDisclaimers::set($length);
            self::assertCount(20, $set, "$length set should have 20 entries");
            foreach ($set as $entry) {
                self::assertNotSame('', trim($entry));
            }
        }
    }

    public function testLongSetStaysWithinTheTwoLineBudget(): void
    {
        // The footer reserves a fixed two-line box at ~46rem; keep entries short
        // enough that none overflow (the CSS guarantee documented in base.html.twig).
        foreach (WellnessDisclaimers::set(WellnessDisclaimers::LONG) as $entry) {
            self::assertLessThanOrEqual(160, mb_strlen($entry), "Too long for the footer: $entry");
        }
    }

    public function testRandomReturnsAMemberOfTheRequestedSet(): void
    {
        for ($i = 0; $i < 50; $i++) {
            self::assertContains(
                WellnessDisclaimers::random(WellnessDisclaimers::LONG),
                WellnessDisclaimers::set(WellnessDisclaimers::LONG),
            );
            self::assertContains(
                WellnessDisclaimers::random(WellnessDisclaimers::SHORT),
                WellnessDisclaimers::set(WellnessDisclaimers::SHORT),
            );
        }
    }

    public function testRandomDefaultsToTheLongSet(): void
    {
        self::assertContains(
            WellnessDisclaimers::random(),
            WellnessDisclaimers::set(WellnessDisclaimers::LONG),
        );
    }

    public function testUnknownLengthThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WellnessDisclaimers::set('medium');
    }
}

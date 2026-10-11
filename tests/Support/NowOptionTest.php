<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\NowOption;
use PHPUnit\Framework\TestCase;

/**
 * The scheduled commands' shared `--now` parser: a typo'd impossible date must be
 * rejected, not silently normalized onto a different day.
 */
final class NowOptionTest extends TestCase
{
    public function testAbsentMeansNow(): void
    {
        self::assertEqualsWithDelta(time(), NowOption::parse(null)->getTimestamp(), 5);
    }

    public function testParsesARealDate(): void
    {
        self::assertSame('2026-07-01 20:00', NowOption::parse('2026-07-01 20:00')->format('Y-m-d H:i'));
    }

    public function testRejectsAnImpossibleCalendarDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('would silently become 2026-03-02');

        NowOption::parse('2026-02-30 10:00');
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Could not parse --now');

        NowOption::parse('next blursday');
    }
}

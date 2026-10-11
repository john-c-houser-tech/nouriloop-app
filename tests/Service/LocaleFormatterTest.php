<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LocaleFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\LocaleSwitcher;

final class LocaleFormatterTest extends TestCase
{
    private function formatter(string $locale): LocaleFormatter
    {
        return new LocaleFormatter(new LocaleSwitcher($locale, []));
    }

    public function testDateSkeletonResolvesPerLocale(): void
    {
        $d = new \DateTimeImmutable('2026-07-18 15:05:00', new \DateTimeZone('America/New_York'));

        self::assertSame('Jul 18, 2026', $this->formatter('en')->date($d, 'yMMMd'));
        self::assertSame('18 jul 2026', $this->formatter('es')->date($d, 'yMMMd'));
        self::assertSame('Sat, Jul 18', $this->formatter('en')->date($d, 'EEEMMMd'));
        self::assertSame('sáb, 18 jul', $this->formatter('es')->date($d, 'EEEMMMd'));
    }

    public function testWallClockNumeralsRenderVerbatimWithoutExplicitTimezone(): void
    {
        // A stored wall-clock value formats in its own zone — 15:05 stays 15:05.
        $d = new \DateTimeImmutable('2026-07-18 15:05:00', new \DateTimeZone('America/New_York'));

        // ICU 72+ separates the AM/PM marker with a narrow no-break space.
        self::assertSame("3:05\u{202F}PM", $this->formatter('en')->date($d, 'jm'));
    }

    public function testExplicitTimezoneConvertsTheInstant(): void
    {
        // 15:05 New York = 12:05 Los Angeles.
        $d = new \DateTimeImmutable('2026-07-18 15:05:00', new \DateTimeZone('America/New_York'));

        self::assertSame(
            "12:05\u{202F}PM",
            $this->formatter('en')->date($d, 'jm', new \DateTimeZone('America/Los_Angeles')),
        );
    }

    public function testAtomStringKeepsItsEmbeddedOffset(): void
    {
        self::assertSame('Jul 18', $this->formatter('en')->date('2026-07-18T23:30:00-07:00', 'MMMd'));
    }

    public function testNumberFormatsPerLocale(): void
    {
        self::assertSame('1,234', $this->formatter('en')->number(1234.4));
        self::assertSame('1.234', $this->formatter('es')->number(1234.4));
        self::assertSame('1,234.6', $this->formatter('en')->number(1234.56, 1));
        self::assertSame('1.234,6', $this->formatter('es')->number(1234.56, 1));
    }
}

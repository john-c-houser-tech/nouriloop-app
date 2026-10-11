<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\User;
use App\Enum\UnitSystem;
use App\Service\LocaleFormatter;
use App\Service\UnitConverter;
use App\Twig\UnitExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Translation\LocaleSwitcher;

final class UnitExtensionTest extends TestCase
{
    private function extension(?UnitSystem $system, string $locale = 'en'): UnitExtension
    {
        $user = null;
        if ($system !== null) {
            $user = new User();
            $user->setEmail('units@test.local');
            $user->setUnitSystem($system);
        }

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new UnitExtension(
            $security,
            new UnitConverter(),
            new LocaleFormatter(new LocaleSwitcher($locale, [])),
        );
    }

    public function testImperialUserSeesCanonicalMgdl(): void
    {
        $ext = $this->extension(UnitSystem::Imperial);

        self::assertSame('mg/dL', $ext->glucoseUnit());
        self::assertSame('142', $ext->glucoseDisplay(142.0));
        self::assertSame(142.0, $ext->glucoseValue(142.0));
    }

    public function testMetricUserSeesMmolWithOneDecimal(): void
    {
        $ext = $this->extension(UnitSystem::Metric);

        self::assertSame('mmol/L', $ext->glucoseUnit());
        // 100 mg/dL / 18.0182 ≈ 5.55 → toDisplay rounds to 5.55, shown as 5.6.
        self::assertSame('5.6', $ext->glucoseDisplay(100.0));
        self::assertSame(5.55, $ext->glucoseValue(100.0));
    }

    public function testSeriesConversionKeepsMinutes(): void
    {
        $ext = $this->extension(UnitSystem::Metric);

        $converted = $ext->glucoseSeriesValue([
            ['minutes' => -15, 'value' => 90.0],
            ['minutes' => 60, 'value' => 180.0],
        ]);

        self::assertSame(-15, $converted[0]['minutes']);
        self::assertSame(4.99, $converted[0]['value']);
        self::assertSame(9.99, $converted[1]['value']);
    }

    public function testAreaScalesByTheSameLinearFactor(): void
    {
        // 1200 mg/dL·min → ≈ 66.6 mmol/L·min, shown whole.
        self::assertSame('67', $this->extension(UnitSystem::Metric)->glucoseAreaDisplay(1200.0));
        self::assertSame('1,200', $this->extension(UnitSystem::Imperial)->glucoseAreaDisplay(1200.0));
    }

    public function testNumbersFollowTheCurrentLocale(): void
    {
        self::assertSame('1.200', $this->extension(UnitSystem::Imperial, 'es')->glucoseAreaDisplay(1200.0));
    }
}

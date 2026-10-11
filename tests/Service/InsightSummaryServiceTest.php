<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\UnitSystem;
use App\Service\InsightSummaryService;
use App\Service\LocaleFormatter;
use App\Service\UnitConverter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\LocaleSwitcher;

final class InsightSummaryServiceTest extends TestCase
{
    private const TODAY = '2026-06-14';

    private function build(array $ctx): array
    {
        $ctx['today'] ??= new \DateTimeImmutable(self::TODAY);

        $translator = new \Symfony\Component\Translation\Translator('en');
        $translator->addLoader('yaml', new \Symfony\Component\Translation\Loader\YamlFileLoader());
        $translator->addResource(
            'yaml',
            __DIR__ . '/../../translations/messages+intl-icu.en.yaml',
            'en',
            'messages+intl-icu',
        );

        return (new InsightSummaryService(
            new UnitConverter(),
            $translator,
            new LocaleFormatter(new LocaleSwitcher('en', [])),
        ))->build($ctx);
    }

    /** Find a card by key across all theme groups. */
    private function card(array $groups, string $key): ?array
    {
        foreach ($groups as $group) {
            foreach ($group['cards'] as $card) {
                if ($card['key'] === $key) {
                    return $card;
                }
            }
        }

        return null;
    }

    public function testProInflammatoryDayAlerts(): void
    {
        $groups = $this->build([
            'inflammationDaily' => [
                ['date' => '2026-06-13', 'score' => -2.0, 'band' => 'mild-anti'],
                ['date' => '2026-06-14', 'score' => 5.0,  'band' => 'strong-pro'],
            ],
        ]);

        self::assertSame(InsightSummaryService::ALERT, $this->card($groups, 'inflammation')['status']);
    }

    public function testAntiInflammatoryDayIsOk(): void
    {
        $groups = $this->build([
            'inflammationDaily' => [['date' => '2026-06-14', 'score' => -5.0, 'band' => 'strong-anti']],
        ]);

        self::assertSame(InsightSummaryService::OK, $this->card($groups, 'inflammation')['status']);
    }

    public function testInRangeCaloriesAreOk(): void
    {
        $groups = $this->build([
            'calorieTotals' => [
                ['date' => '2026-06-13', 'calories' => '2100'],
                ['date' => '2026-06-14', 'calories' => '2300'],
            ],
        ]);

        self::assertSame(InsightSummaryService::OK, $this->card($groups, 'calories')['status']);
    }

    public function testHighCaloriesAlert(): void
    {
        $groups = $this->build([
            'calorieTotals' => [['date' => '2026-06-14', 'calories' => '4200']],
        ]);

        $card = $this->card($groups, 'calories');
        self::assertSame(InsightSummaryService::ALERT, $card['status']);
        self::assertSame('above range', $card['note']);
    }

    public function testHighGlucoseAlerts(): void
    {
        $groups = $this->build([
            'healthAggByType' => [
                'blood_glucose' => [['bucket' => self::TODAY, 'avg' => '190', 'min' => '180', 'max' => '200']],
            ],
            'metricMeta' => ['blood_glucose' => ['label' => 'Blood glucose', 'unit' => 'mg/dL']],
        ]);

        $card = $this->card($groups, 'health-blood_glucose');
        self::assertNotNull($card);
        self::assertSame('blood_glucose', $card['metric']);
        self::assertSame(InsightSummaryService::ALERT, $card['status']);
    }

    public function testStaleReadingDowngradesToWatch(): void
    {
        $tenDaysAgo = (new \DateTimeImmutable(self::TODAY))->modify('-10 days')->format('Y-m-d');
        $groups = $this->build([
            'healthAggByType' => [
                'weight' => [['bucket' => $tenDaysAgo, 'avg' => '80', 'min' => '80', 'max' => '80']],
            ],
            'metricMeta' => ['weight' => ['label' => 'Weight', 'unit' => 'kg']],
        ]);

        $card = $this->card($groups, 'health-weight');
        self::assertSame(InsightSummaryService::WATCH, $card['status']);
        self::assertStringContainsString('days ago', $card['note']);
    }

    public function testRecentWeightIsOk(): void
    {
        $groups = $this->build([
            'healthAggByType' => [
                'weight' => [['bucket' => self::TODAY, 'avg' => '80', 'min' => '80', 'max' => '80']],
            ],
            'metricMeta' => ['weight' => ['label' => 'Weight', 'unit' => 'kg']],
        ]);

        self::assertSame(InsightSummaryService::OK, $this->card($groups, 'health-weight')['status']);
    }

    public function testImperialWeightConvertsToPounds(): void
    {
        $groups = $this->build([
            'healthAggByType' => [
                'weight' => [['bucket' => self::TODAY, 'avg' => '176.37', 'min' => '176.37', 'max' => '176.37']],
            ],
            'metricMeta'  => ['weight' => ['label' => 'Weight', 'unit' => 'lb']],
            'unitSystem'  => UnitSystem::Imperial,
        ]);

        // Controller already converted the aggregate to lb; the tile just formats it.
        $card = $this->card($groups, 'health-weight');
        self::assertSame('176.4', $card['headline']);
        self::assertSame('lb', $card['unit']);
    }

    public function testRisingStepsAreOk(): void
    {
        $groups = $this->build([
            'healthAggByType' => [
                'steps' => [
                    ['bucket' => '2026-06-11', 'avg' => '3000', 'min' => '3000', 'max' => '3000'],
                    ['bucket' => '2026-06-12', 'avg' => '5000', 'min' => '5000', 'max' => '5000'],
                    ['bucket' => '2026-06-13', 'avg' => '7000', 'min' => '7000', 'max' => '7000'],
                    ['bucket' => self::TODAY,   'avg' => '9000', 'min' => '9000', 'max' => '9000'],
                ],
            ],
            'metricMeta' => ['steps' => ['label' => 'Steps', 'unit' => 'steps']],
        ]);

        $card = $this->card($groups, 'health-steps');
        self::assertSame(InsightSummaryService::OK, $card['status']);
        self::assertSame('up', $card['trend']);
        self::assertSame('trending up', $card['note']);
    }

    public function testFallingStepsAlert(): void
    {
        $groups = $this->build([
            'healthAggByType' => [
                'steps' => [
                    ['bucket' => '2026-06-11', 'avg' => '9000', 'min' => '9000', 'max' => '9000'],
                    ['bucket' => '2026-06-12', 'avg' => '7000', 'min' => '7000', 'max' => '7000'],
                    ['bucket' => '2026-06-13', 'avg' => '5000', 'min' => '5000', 'max' => '5000'],
                    ['bucket' => self::TODAY,   'avg' => '3000', 'min' => '3000', 'max' => '3000'],
                ],
            ],
            'metricMeta' => ['steps' => ['label' => 'Steps', 'unit' => 'steps']],
        ]);

        $card = $this->card($groups, 'health-steps');
        self::assertSame(InsightSummaryService::ALERT, $card['status']);
        self::assertSame('down', $card['trend']);
        self::assertSame('trending down', $card['note']);
    }

    public function testMicronutrientsBelowTargetAlert(): void
    {
        $groups = $this->build([
            'calorieTotals' => [['date' => '2026-06-14', 'calories' => '2000']],
            'days'          => 30,
            // Supplement-inclusive gaps from SupplementRecommendationService; a gap
            // well below RDA (< 40%) escalates the tile to alert.
            'microGaps'     => [
                ['pct' => 12.0],
                ['pct' => 60.0],
            ],
        ]);

        $card = $this->card($groups, 'micros');
        self::assertSame(InsightSummaryService::ALERT, $card['status']);
        self::assertSame('2', $card['headline']);
        self::assertSame('below target', $card['unit']);
        self::assertSame('below 75% RDA', $card['note']);
        // Links to the Supplements page over the same window, not an on-page panel.
        self::assertSame('app_supplements', $card['linkRoute']);
        self::assertSame(['days' => 30], $card['linkParams']);
    }

    public function testMicronutrientsModestGapIsWatchNotAlert(): void
    {
        $groups = $this->build([
            'calorieTotals' => [['date' => '2026-06-14', 'calories' => '2000']],
            'microGaps'     => [['pct' => 60.0]], // below target but not far below
        ]);

        self::assertSame(InsightSummaryService::WATCH, $this->card($groups, 'micros')['status']);
    }

    public function testMicronutrientsAllAtTargetIsOk(): void
    {
        $groups = $this->build([
            'calorieTotals' => [['date' => '2026-06-14', 'calories' => '2000']],
            'microGaps'     => [], // meals logged, no gaps → all at target
        ]);

        $card = $this->card($groups, 'micros');
        self::assertSame(InsightSummaryService::OK, $card['status']);
        self::assertSame('0', $card['headline']);
        self::assertSame('', $card['unit']); // no misleading "0 below target"
        self::assertSame('all at target', $card['note']);
        self::assertSame('app_supplements', $card['linkRoute']);
    }

    public function testMicronutrientsMutedWithoutMeals(): void
    {
        // No meals logged → muted, regardless of gaps (consistent with calories/macros).
        $groups = $this->build(['microGaps' => [['pct' => 10.0]]]);

        $card = $this->card($groups, 'micros');
        self::assertSame(InsightSummaryService::MUTED, $card['status']);
        self::assertFalse($card['available']);
        self::assertArrayNotHasKey('linkRoute', $card);
    }

    public function testMicronutrientsLinkUsesTheSelectedWindow(): void
    {
        // Both pages offer 7/14/30/90, so the tile links over the exact window the
        // gaps were computed for — including 90, with no clamping.
        $groups = $this->build([
            'calorieTotals' => [['date' => '2026-06-14', 'calories' => '2000']],
            'days'          => 90,
            'microGaps'     => [['pct' => 50.0]],
        ]);

        self::assertSame(['days' => 90], $this->card($groups, 'micros')['linkParams']);
    }

    public function testHighSymptomLevelAlerts(): void
    {
        $groups = $this->build([
            'symptomSeriesByType' => [
                3 => ['label' => 'Joint pain', 'color' => '#dc3545', 'higherIsBetter' => false,
                      'data' => ['2026-06-13' => 4, '2026-06-14' => 8]],
            ],
        ]);

        $card = $this->card($groups, 'symptom-3');
        self::assertSame('symptom-3', $card['panel']); // its own per-symptom panel
        self::assertSame('8', $card['headline']);
        self::assertSame(InsightSummaryService::ALERT, $card['status']);
    }

    public function testHigherIsBetterSymptomFlipsPolarity(): void
    {
        // Energy of 2/10 is bad even though the number is low → alert.
        $groups = $this->build([
            'symptomSeriesByType' => [
                7 => ['label' => 'Energy', 'color' => '#20c997', 'higherIsBetter' => true,
                      'data' => ['2026-06-14' => 2]],
            ],
        ]);

        self::assertSame(InsightSummaryService::ALERT, $this->card($groups, 'symptom-7')['status']);
    }

    public function testEnabledButUnloggedSymptomIsMuted(): void
    {
        $groups = $this->build([
            'symptomSeriesByType' => [
                3 => ['label' => 'Joint pain', 'color' => '#dc3545', 'higherIsBetter' => false, 'data' => []],
            ],
        ]);

        $card = $this->card($groups, 'symptom-3');
        self::assertSame(InsightSummaryService::MUTED, $card['status']);
        self::assertFalse($card['available']);
    }

    public function testRisingSeriesTrendsUp(): void
    {
        $groups = $this->build([
            'calorieTotals' => [
                ['date' => '2026-06-10', 'calories' => '1500'],
                ['date' => '2026-06-11', 'calories' => '1600'],
                ['date' => '2026-06-12', 'calories' => '2400'],
                ['date' => '2026-06-13', 'calories' => '2600'],
            ],
        ]);

        self::assertSame('up', $this->card($groups, 'calories')['trend']);
    }

    public function testSteadySeriesTrendsFlat(): void
    {
        $groups = $this->build([
            'calorieTotals' => [
                ['date' => '2026-06-10', 'calories' => '2000'],
                ['date' => '2026-06-11', 'calories' => '2010'],
                ['date' => '2026-06-12', 'calories' => '1990'],
                ['date' => '2026-06-13', 'calories' => '2005'],
            ],
        ]);

        self::assertSame('flat', $this->card($groups, 'calories')['trend']);
    }

    public function testTooFewPointsHaveNoTrend(): void
    {
        $groups = $this->build([
            'calorieTotals' => [
                ['date' => '2026-06-13', 'calories' => '2000'],
                ['date' => '2026-06-14', 'calories' => '2500'],
            ],
        ]);

        self::assertNull($this->card($groups, 'calories')['trend']);
    }

    public function testNoDataYieldsMutedUnavailableCards(): void
    {
        $groups = $this->build([]);

        $inflammation = $this->card($groups, 'inflammation');
        self::assertSame(InsightSummaryService::MUTED, $inflammation['status']);
        self::assertFalse($inflammation['available']);

        // The "body over time" group has no metrics at all → no cards.
        $body = array_values(array_filter($groups, static fn(array $g): bool => $g['theme'] === 'body'))[0];
        self::assertSame([], $body['cards']);
    }
}

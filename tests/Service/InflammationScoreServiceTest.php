<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Intake\DailyIntake;
use App\Repository\NutritionDataRepository;
use App\Repository\MealEntryRepository;
use App\Repository\SupplementRepository;
use App\Service\InflammationScoreService;
use App\Tests\Intake\StubsSupplements;
use App\Service\UserTime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class InflammationScoreServiceTest extends TestCase
{
    use StubsSupplements;

    /** @param array<string, float> $supplementNutrients */
    private function service(
        ?MealEntryRepository $repo = null,
        array $supplementNutrients = [],
        string $locale = 'en',
    ): InflammationScoreService {
        $supplements = $this->createStub(SupplementRepository::class);
        $supplements->method('findActive')->willReturn(self::takenSince($supplementNutrients));

        return new InflammationScoreService(
            $repo ?? $this->createStub(MealEntryRepository::class),
            new DailyIntake($this->createStub(NutritionDataRepository::class), $supplements, new UserTime()),
            $this->translator($locale),
        );
    }

    /**
     * Real translator over the real catalogs, so factor-label assertions
     * exercise the same inflammation.factor.* entries production uses.
     */
    private function translator(string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['en', 'es'] as $l) {
            $translator->addResource(
                'yaml',
                __DIR__ . '/../../translations/messages+intl-icu.' . $l . '.yaml',
                $l,
                'messages+intl-icu',
            );
        }

        return $translator;
    }

    public function testProInflammatoryMealScoresPositive(): void
    {
        $out = $this->service()->scoreMeal(
            ['saturatedFat' => 30.0, 'sugar' => 40.0, 'sodium' => 2000.0],
            [],
        );

        self::assertGreaterThan(0, $out['score']);
        self::assertSame('Saturated fat', $out['factors'][0]['label']); // largest factor
    }

    public function testFactorLabelsFollowTheTranslatorLocale(): void
    {
        $out = $this->service(locale: 'es')->scoreMeal(
            ['saturatedFat' => 30.0],
            [['name' => 'Baby spinach'], ['name' => 'Bacon strips']],
        );

        $labels = array_column($out['factors'], 'label');
        self::assertContains('Grasa saturada', $labels);
        self::assertContains('Alimento: Espinaca', $labels);
        self::assertContains('Alimento: Tocino', $labels);
        self::assertNotContains('Saturated fat', $labels);
    }

    public function testAntiInflammatoryMealScoresNegative(): void
    {
        $out = $this->service()->scoreMeal(
            ['fiber' => 20.0, 'vitaminC' => 90.0, 'vitaminD' => 20.0, 'vitaminE' => 15.0],
            [['name' => 'Baby spinach']],
        );

        self::assertLessThan(0, $out['score']);
    }

    public function testFoodKeywordsMoveScoreBothWays(): void
    {
        $bacon  = $this->service()->scoreMeal([], [['name' => 'Crispy bacon strips']]);
        $salmon = $this->service()->scoreMeal([], [['name' => 'Grilled salmon fillet']]);

        self::assertGreaterThan(0, $bacon['score']);
        self::assertLessThan(0, $salmon['score']);
    }

    public function testFoodInfluenceIsCappedPerMeal(): void
    {
        $out = $this->service()->scoreMeal([], [
            ['name' => 'bacon'], ['name' => 'sausage'], ['name' => 'salami'],
            ['name' => 'pepperoni'], ['name' => 'soda'],
        ]);

        // Raw food sum would be 10; capped at +5.
        self::assertEqualsWithDelta(5.0, $out['score'], 0.001);
    }

    public function testBandThresholds(): void
    {
        self::assertSame('strong-anti', InflammationScoreService::band(-5));
        self::assertSame('mild-anti', InflammationScoreService::band(-2));
        self::assertSame('neutral', InflammationScoreService::band(0));
        self::assertSame('mild-pro', InflammationScoreService::band(2));
        self::assertSame('strong-pro', InflammationScoreService::band(5));
    }

    public function testClampDailyClampsToBand(): void
    {
        $svc = $this->service();
        self::assertSame(10.0, $svc->clampDaily(42.0));
        self::assertSame(-10.0, $svc->clampDaily(-42.0));
        self::assertSame(3.5, $svc->clampDaily(3.456));
    }

    public function testDailyScoresGroupByDayClampAndSort(): void
    {
        $repo = $this->createStub(MealEntryRepository::class);
        $repo->method('findWithNutritionForRange')->willReturn([
            $this->meal('2026-06-10 08:00:00', ['saturatedFat' => 40.0, 'sugar' => 60.0]),
            $this->meal('2026-06-10 19:00:00', ['saturatedFat' => 40.0, 'sugar' => 60.0]),
            $this->meal('2026-06-09 12:00:00', ['fiber' => 25.0, 'vitaminC' => 90.0]),
        ]);

        $out = $this->service($repo)->dailyScores(
            new \App\Entity\User(),
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );

        self::assertSame(['2026-06-09', '2026-06-10'], array_keys($out)); // sorted
        self::assertSame(10.0, $out['2026-06-10']['score']);              // clamped
        self::assertSame('strong-pro', $out['2026-06-10']['band']);
        self::assertLessThan(0, $out['2026-06-09']['score']);
    }

    public function testActiveFiberSupplementLowersDailyScore(): void
    {
        $repo = $this->createStub(MealEntryRepository::class);
        $repo->method('findWithNutritionForRange')->willReturn([
            $this->meal('2026-06-10 08:00:00', ['saturatedFat' => 10.0]),
        ]);
        $user = new \App\Entity\User();
        $from = new \DateTimeImmutable('2026-06-01');
        $to   = new \DateTimeImmutable('2026-06-30');

        $without = $this->service($repo)->dailyScores($user, $from, $to);
        $with    = $this->service($repo, ['fiber' => 20.0])->dailyScores($user, $from, $to);

        // Supplement fiber is anti-inflammatory → pulls the day's score down.
        self::assertLessThan($without['2026-06-10']['score'], $with['2026-06-10']['score']);
        $labels = array_column($with['2026-06-10']['factors'], 'label');
        self::assertContains('Fiber (supplement)', $labels);
    }

    /**
     * @param array<string, float> $nutrients
     */
    private function meal(string $loggedAt, array $nutrients): MealEntry
    {
        $meal = $this->createStub(MealEntry::class);
        $meal->method('getNutritionData')->willReturn(NutritionData::fromArray($nutrients));
        $meal->method('getIngredients')->willReturn([]);
        $meal->method('getLoggedAt')->willReturn(new \DateTimeImmutable($loggedAt));

        return $meal;
    }
}

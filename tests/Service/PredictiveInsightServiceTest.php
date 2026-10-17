<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Ai\AiClient;
use App\Ai\AiResult;
use App\Dto\ScenarioRequest;
use App\Entity\Favorite;
use App\Entity\Supplement;
use App\Entity\User;
use App\Enum\HealthMetricType;
use App\Enum\ScenarioType;
use App\Enum\UnitSystem;
use App\Repository\FavoriteRepository;
use App\Repository\HealthMetricRepository;
use App\Intake\DailyIntake;
use App\Intake\IntakeAverage;
use App\Repository\MealEntryRepository;
use App\Repository\SupplementRepository;
use App\Repository\SymptomDayRepository;
use App\Repository\SymptomTypeRepository;
use App\Service\InflammationScoreService;
use App\Service\PredictiveInsightService;
use App\Service\SupplementRecommendationService;
use App\Service\LocaleFormatter;
use App\Service\TonePromptBuilder;
use App\Service\UnitConverter;
use App\Service\UserTime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Asserts the in-memory projection *math* with a non-trivial baseline — the
 * displacement model, supplement deltas, weight projection, and gap-count
 * direction — by stubbing the leaf services/repos so the orchestration's
 * arithmetic is exercised with controlled numbers. (The E2E test covers the
 * gate/CSRF/validation HTTP path; this covers the numbers.)
 */
final class PredictiveInsightServiceTest extends TestCase
{
    private const TZ = 'America/New_York';

    public function testFavoriteDailyDisplacementMath(): void
    {
        // Baseline: 600 kcal / 30 g protein / 6 g fiber averaged across the window,
        // 30 meals over 10 logged days → avgMealsPerDay = 3. Daily inflammation 3.0.
        $favorite = $this->favorite(['calories' => 300.0, 'protein' => 15.0, 'fiber' => 9.0]);

        $service = $this->service(
            foodAverages: ['calories' => 600.0, 'protein' => 30.0, 'fiber' => 6.0],
            mealCount: 30,
            dayCount: 10,
            dailyScore: 3.0,
            favoriteScore: -6.0,
            // Projected diet (700 kcal) closes one gap vs the baseline (600 kcal).
            gapsByCalories: fn (float $cal): array => $cal >= 700
                ? [['label' => 'Iron']]
                : [['label' => 'Iron'], ['label' => 'Calcium']],
            favorite: $favorite,
        );

        $req = new ScenarioRequest(
            type: ScenarioType::FavoriteDaily,
            durationWeeks: 2,
            dimensions: ['inflammation', 'nutrition'],
            favoriteId: 1,
        );

        $rows = $this->rowsByLabel($service->compute($this->user(), $req));

        // Displacement: subtract one average meal (avg / 3), then add the favorite.
        // Row values are locale-formatted display strings (en here).
        self::assertSame('700.0', $rows['Calories']['projectedValue']);        // 600 - 200 + 300
        self::assertSame('35.0', $rows['Protein']['projectedValue']);          // 30 - 10 + 15
        self::assertSame('13.0', $rows['Fiber']['projectedValue']);           // 6 - 2 + 9
        // Inflammation: 3.0 - (3.0 / 3) + (-6.0) = -4.0 (clamped, in band).
        self::assertSame('-4.0', $rows['Inflammation Score (avg)']['projectedValue']);
        self::assertSame('better', $rows['Inflammation Score (avg)']['direction']);
        // Gap count improves 2 → 1 (values now arrive pre-pluralized).
        self::assertSame('2 nutrients', $rows['Nutrient gaps (< 75% RDA)']['currentValue']);
        self::assertSame('1 nutrient', $rows['Nutrient gaps (< 75% RDA)']['projectedValue']);
        self::assertSame('better', $rows['Nutrient gaps (< 75% RDA)']['direction']);
    }

    public function testWeightProjectionIsKgOverTheSelectedWindow(): void
    {
        // Baseline 600 kcal; the favorite adds a net +100 kcal/day after displacement
        // (600 - 200 + 300 = 700). Over 2 weeks: 100 * 7 * 2 / 7700 ≈ 0.18 kg → +0.2.
        $favorite = $this->favorite(['calories' => 300.0]);

        $service = $this->service(
            foodAverages: ['calories' => 600.0],
            mealCount: 30,
            dayCount: 10,
            dailyScore: 0.0,
            favoriteScore: 0.0,
            gapsByCalories: fn (float $cal): array => [],
            favorite: $favorite,
            latestWeight: 80.0,
        );

        $req = new ScenarioRequest(
            type: ScenarioType::FavoriteDaily,
            durationWeeks: 2,
            dimensions: ['weight'],
            favoriteId: 1,
        );

        $rows = $this->rowsByLabel($service->compute($this->user(), $req));

        self::assertArrayHasKey('Weight (est. after 2 weeks)', $rows);
        self::assertSame('80.2', $rows['Weight (est. after 2 weeks)']['projectedValue']);
        self::assertSame('kg', $rows['Weight (est. after 2 weeks)']['unit']);
    }

    public function testWeightProjectionConvertsToImperialForDisplay(): void
    {
        // Same math as above (80.0 kg → 80.2 kg projected), but an imperial user
        // sees lb: 80.0 kg = 176.4 lb current, 80.2 kg ≈ 176.8 lb projected.
        $favorite = $this->favorite(['calories' => 300.0]);

        $service = $this->service(
            foodAverages: ['calories' => 600.0],
            mealCount: 30,
            dayCount: 10,
            dailyScore: 0.0,
            favoriteScore: 0.0,
            gapsByCalories: fn (float $cal): array => [],
            favorite: $favorite,
            latestWeight: 80.0,
        );

        $req = new ScenarioRequest(
            type: ScenarioType::FavoriteDaily,
            durationWeeks: 2,
            dimensions: ['weight'],
            favoriteId: 1,
        );

        $user = $this->user();
        $user->setUnitSystem(UnitSystem::Imperial);
        $rows = $this->rowsByLabel($service->compute($user, $req));

        self::assertSame('176.4', $rows['Weight (est. after 2 weeks)']['currentValue']);
        self::assertSame('176.8', $rows['Weight (est. after 2 weeks)']['projectedValue']);
        self::assertSame('lb', $rows['Weight (est. after 2 weeks)']['unit']);
    }

    public function testSupplementAddInflammationDelta(): void
    {
        // Adding a supplement scored at -2.0 shifts the daily inflammation 3.0 → 1.0.
        $supplement = new Supplement();
        $supplement->setUser($this->user());
        $supplement->setName('Magnesium');
        $supplement->setServingsPerDay(1.0);
        $supplement->setActive(false);
        $supplement->setNutrients(['magnesium' => 200.0]);

        $service = $this->service(
            foodAverages: ['calories' => 600.0],
            mealCount: 30,
            dayCount: 10,
            dailyScore: 3.0,
            favoriteScore: 0.0,
            gapsByCalories: fn (float $cal): array => [],
            supplement: $supplement,
            supplementScore: -2.0,
        );

        $req = new ScenarioRequest(
            type: ScenarioType::SupplementAdd,
            durationWeeks: 2,
            dimensions: ['inflammation'],
            supplementId: 1,
        );

        $rows = $this->rowsByLabel($service->compute($this->user(), $req));

        self::assertSame('1.0', $rows['Inflammation Score (avg)']['projectedValue']);
        self::assertSame('better', $rows['Inflammation Score (avg)']['direction']);
    }

    public function testTheBaselineCoversEveryNutrient(): void
    {
        $service = $this->service(
            foodAverages: ['calories' => 2000.0, 'saturatedFat' => 22.0, 'sugar' => 45.0, 'phosphorus' => 900.0],
            mealCount: 9,
            dayCount: 3,
            dailyScore: 0.0,
            favoriteScore: 0.0,
            gapsByCalories: static fn (): array => [],
        );

        $food = $service->fetchBaseline($this->user())['foodAverages'];

        // The 23 the old average query held, plus saturated fat, sugar and phosphorus.
        self::assertSame(22.0, $food['saturatedFat']);
        self::assertSame(45.0, $food['sugar']);
        self::assertSame(900.0, $food['phosphorus']);
    }

    public function testGapRowSkippedWhenBaselineHasNoNutritionData(): void
    {
        // No logged nutrition, no active supplements: the baseline gap list is the
        // empty-input [] — comparing it against a supplement-only projection would
        // read "0 → N gaps, worse", so the row is omitted entirely.
        $supplement = new Supplement();
        $supplement->setUser($this->user());
        $supplement->setName('Multivitamin');
        $supplement->setServingsPerDay(1.0);
        $supplement->setActive(false);
        $supplement->setNutrients(['vitaminC' => 80.0]);

        $service = $this->service(
            foodAverages: [],
            mealCount: 0,
            dayCount: 0,
            dailyScore: 0.0,
            favoriteScore: 0.0,
            gapsByCalories: fn (float $cal): array => [['label' => 'Iron'], ['label' => 'Calcium']],
            supplement: $supplement,
        );

        $req = new ScenarioRequest(
            type: ScenarioType::SupplementAdd,
            durationWeeks: 2,
            dimensions: ['nutrition'],
            supplementId: 1,
        );

        $rows = $this->rowsByLabel($service->compute($this->user(), $req));

        self::assertArrayHasKey('Calories', $rows, 'macro rows still render');
        self::assertArrayNotHasKey('Nutrient gaps (< 75% RDA)', $rows, 'gap row skipped on an empty baseline');
    }

    /**
     * @param array<string, float> $foodAverages
     * @param callable(float): list<array{label: string}> $gapsByCalories
     */
    private function service(
        array $foodAverages,
        int $mealCount,
        int $dayCount,
        float $dailyScore,
        float $favoriteScore,
        callable $gapsByCalories,
        ?Favorite $favorite = null,
        ?Supplement $supplement = null,
        float $supplementScore = 0.0,
        ?float $latestWeight = null,
    ): PredictiveInsightService {
        // No meal days → no average at all (ADR 0002); otherwise food over
        // $dayCount meal days with no active supplements.
        $intake = $this->createStub(DailyIntake::class);
        $intake->method('average')->willReturn(
            array_sum($foodAverages) > 0.0 ? new IntakeAverage($foodAverages, [], max(1, $dayCount)) : null,
        );

        $mealRepo = $this->createStub(MealEntryRepository::class);
        $mealRepo->method('findWithNutritionForRange')->willReturn(array_fill(0, $mealCount, null));

        $supplementRepo = $this->createStub(SupplementRepository::class);
        $supplementRepo->method('find')->willReturn($supplement);

        $healthRepo = $this->createStub(HealthMetricRepository::class);
        $healthRepo->method('findLatestValue')->willReturnCallback(
            static fn (User $u, HealthMetricType $t): ?float => $t === HealthMetricType::Weight ? $latestWeight : null,
        );

        $symptomDayRepo = $this->createStub(SymptomDayRepository::class);
        $symptomDayRepo->method('getLevelsByTypeForRange')->willReturn([]);
        $symptomTypeRepo = $this->createStub(SymptomTypeRepository::class);
        $symptomTypeRepo->method('findEnabledByUserOrdered')->willReturn([]);

        $favoriteRepo = $this->createStub(FavoriteRepository::class);
        $favoriteRepo->method('find')->willReturn($favorite);

        $inflammation = $this->createStub(InflammationScoreService::class);
        // $dayCount logged days each at $dailyScore → mealDayCount + avg both controlled.
        $days = [];
        for ($i = 0; $i < $dayCount; $i++) {
            $days['2026-06-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)] = [
                'date' => '', 'score' => $dailyScore, 'band' => '', 'factors' => [],
            ];
        }
        $inflammation->method('dailyScores')->willReturn($days);
        $inflammation->method('clampDaily')->willReturnCallback(
            static fn (float $raw): float => round(max(-10.0, min(10.0, $raw)), 1),
        );
        // Favorite is scored with its ingredients; a supplement is scored with []
        // ingredients — distinguish on that so each branch gets its own score.
        $inflammation->method('scoreMeal')->willReturnCallback(
            static fn (array $nutrients, array $ingredients): array => [
                'score'   => $ingredients === [] ? $supplementScore : $favoriteScore,
                'factors' => [],
            ],
        );

        $supplementRecs = $this->createStub(SupplementRecommendationService::class);
        $supplementRecs->method('computeGaps')->willReturnCallback(
            static fn (array $intake): array => $gapsByCalories((float) ($intake['calories'] ?? 0)),
        );
        // Mirror the real predicate (a plain stub would return false and hide the
        // gaps row from every test) — the gap row only renders on a real baseline.
        $supplementRecs->method('hasNutritionData')->willReturnCallback(
            static fn (array $intake): bool => array_sum($intake) > 0.0,
        );

        $claude = $this->createStub(AiClient::class);
        $claude->method('complete')->willReturn(new AiResult('A computed projection.', 10, 5));

        return new PredictiveInsightService(
            $intake,
            $mealRepo,
            $supplementRepo,
            $healthRepo,
            $symptomDayRepo,
            $symptomTypeRepo,
            $inflammation,
            $supplementRecs,
            $favoriteRepo,
            new UserTime(),
            new TonePromptBuilder(),
            $claude,
            self::enTranslator(),
            new \App\Service\SymptomLabeler(self::enTranslator()),
            new UnitConverter(),
            new LocaleFormatter(new LocaleSwitcher('en', [])),
            new \App\Service\LocalePromptBuilder(new LocaleSwitcher('en', [])),
        );
    }

    /** @param array<string, float> $nutrients */
    private function favorite(array $nutrients): Favorite
    {
        $favorite = new Favorite();
        $favorite->setUser($this->user());
        $favorite->setName('Test favorite');
        $favorite->setNutrients($nutrients);
        $favorite->setIngredients([['name' => 'something']]);

        return $favorite;
    }

    private function user(): User
    {
        $user = new User();
        $user->setEmail('math@test.local');
        $user->setTimezone(self::TZ);

        return $user;
    }

    /**
     * @return array<string, array{
     *   currentValue: float|int|string, projectedValue: float|int|string,
     *   direction: string, unit: string
     * }>
     */
    private function rowsByLabel(\App\Dto\PredictiveInsight $insight): array
    {
        $out = [];
        foreach ($insight->beforeAfter as $row) {
            $out[$row['label']] = $row;
        }

        return $out;
    }

    private static function enTranslator(): \Symfony\Component\Translation\Translator
    {
        $translator = new \Symfony\Component\Translation\Translator('en');
        $translator->addLoader('yaml', new \Symfony\Component\Translation\Loader\YamlFileLoader());
        $translator->addResource(
            'yaml',
            __DIR__ . '/../../translations/messages+intl-icu.en.yaml',
            'en',
            'messages+intl-icu',
        );

        return $translator;
    }
}

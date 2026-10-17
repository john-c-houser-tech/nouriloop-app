<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Ai\AiClient;
use App\Ai\AiFeature;
use App\Ai\AiResult;
use App\Dto\GlycemicImpact;
use App\Dto\PostprandialResponse;
use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Enum\MealType;
use App\Repository\MealEntryRepository;
use App\Service\GlycemicImpactEstimator;
use App\Service\GlucoseInsightsService;
use App\Service\PostprandialResponseService;
use App\Service\SubscriptionService;
use App\Service\LocalePromptBuilder;
use App\Service\TonePromptBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class GlucoseInsightsServiceTest extends TestCase
{
    /** @var array<int, PostprandialResponse> keyed by spl_object_id($meal) */
    private array $responseByMeal = [];
    /** @var array<int, GlycemicImpact|null> keyed by spl_object_id($response) */
    private array $impactByResponse = [];

    private function response(float $delta, float $iauc, bool $hasCurve = true): PostprandialResponse
    {
        return new PostprandialResponse(
            series: [],
            baseline: 90.0,
            peak: 90.0 + $delta,
            timeToPeakMin: 45,
            delta: $delta,
            iauc: $iauc,
            returnToBaselineMin: 90,
            readingCount: 20,
            hasBaseline: true,
            hasCurve: $hasCurve,
            confidence: $hasCurve ? 'high' : 'low',
        );
    }

    private function impact(float $ratio, string $band): GlycemicImpact
    {
        return new GlycemicImpact(
            estimatedGL: 20.0,
            glCategory: 'medium',
            expectedIAUC: 1000.0,
            measuredIAUC: 1000.0 * $ratio,
            ratio: $ratio,
            band: $band,
        );
    }

    /**
     * Registers a meal with the response and impact the stubs should return for it.
     */
    private function meal(
        PostprandialResponse $response,
        ?GlycemicImpact $impact,
        bool $withNutrition = true,
    ): MealEntry {
        $meal = new MealEntry();
        $meal->setUser(new User());
        $meal->setMealType(MealType::Lunch);
        $meal->setLoggedAt(new \DateTimeImmutable('2026-06-07 12:30:00'));
        if ($withNutrition) {
            $meal->setNutritionData(new NutritionData());
        }

        $this->responseByMeal[spl_object_id($meal)]       = $response;
        $this->impactByResponse[spl_object_id($response)] = $impact;

        return $meal;
    }

    /**
     * @param list<MealEntry> $meals
     */
    private function service(
        array $meals,
        bool $aiEnabled = false,
        ?AiClient $claude = null,
        bool $entitled = true,
    ): GlucoseInsightsService {
        $repo = $this->createStub(MealEntryRepository::class);
        $repo->method('findWithNutritionForRange')->willReturn($meals);

        $responses = $this->createStub(PostprandialResponseService::class);
        $responses->method('forMeals')->willReturnCallback(
            fn(array $ms) => array_map(fn(MealEntry $m) => $this->responseByMeal[spl_object_id($m)] ?? null, $ms),
        );

        $estimator = $this->createStub(GlycemicImpactEstimator::class);
        $estimator->method('estimate')->willReturnCallback(
            fn(NutritionData $n, PostprandialResponse $r) => $this->impactByResponse[spl_object_id($r)] ?? null,
        );

        // Loop+ entitlement defaults on here so most tests exercise the kill-switch
        // gate; pass entitled: false to exercise the subscription-feature gate (the
        // coach's only per-user condition since the admin toggle was retired).
        $subscriptions = $this->createStub(SubscriptionService::class);
        $subscriptions->method('hasFeature')->willReturn($entitled);

        return new GlucoseInsightsService(
            $repo,
            $responses,
            $estimator,
            $claude ?? $this->createStub(AiClient::class),
            new NullLogger(),
            new ArrayAdapter(),
            $subscriptions,
            new TonePromptBuilder(),
            new LocalePromptBuilder(new LocaleSwitcher('en', [])),
            $aiEnabled,
        );
    }

    private function window(): array
    {
        return [new \DateTimeImmutable('-30 days'), new \DateTimeImmutable('tomorrow')];
    }

    public function testEmptyWindowYieldsNoData(): void
    {
        [$from, $to] = $this->window();
        $result = $this->service([])->forUser(new User(), $from, $to);

        self::assertFalse($result->hasData());
        self::assertSame(0, $result->mealCount);
        self::assertNull($result->avgDelta);
        self::assertSame([], $result->topSpikers);
    }

    public function testMealsWithoutCurveAreSkipped(): void
    {
        $meals = [
            $this->meal($this->response(40.0, 1000.0, hasCurve: false), null),
            $this->meal($this->response(20.0, 500.0), $this->impact(0.9, 'as_expected')),
        ];

        [$from, $to] = $this->window();
        $result = $this->service($meals)->forUser(new User(), $from, $to);

        self::assertSame(1, $result->mealCount);
        self::assertSame(20.0, $result->avgDelta);
    }

    public function testAveragesAndRankingOrder(): void
    {
        $meals = [
            $this->meal($this->response(10.0, 200.0), $this->impact(0.8, 'as_expected')),
            $this->meal($this->response(60.0, 2000.0), $this->impact(1.9, 'much_higher')),
            $this->meal($this->response(30.0, 900.0), $this->impact(1.1, 'as_expected')),
        ];

        [$from, $to] = $this->window();
        $result = $this->service($meals)->forUser(new User(), $from, $to);

        self::assertSame(3, $result->mealCount);
        self::assertSame(33.3, $result->avgDelta);                  // (10+60+30)/3
        self::assertSame(1033.3, $result->avgIauc);                 // (200+2000+900)/3

        // Top spikers: highest delta first.
        self::assertSame(60.0, $result->topSpikers[0]->response->delta);
        self::assertSame(30.0, $result->topSpikers[1]->response->delta);
        self::assertSame(10.0, $result->topSpikers[2]->response->delta);

        // Gentlest: lowest delta first.
        self::assertSame(10.0, $result->gentlest[0]->response->delta);
        self::assertSame(60.0, $result->gentlest[count($result->gentlest) - 1]->response->delta);
    }

    public function testAboveExpectedOnlyIncludesRatioOverOneSortedDescending(): void
    {
        $meals = [
            $this->meal($this->response(10.0, 200.0), $this->impact(0.7, 'lower')),       // below → excluded
            $this->meal($this->response(20.0, 800.0), $this->impact(1.4, 'higher')),      // above
            $this->meal($this->response(25.0, 1500.0), $this->impact(2.2, 'much_higher')), // most above
            $this->meal($this->response(15.0, 300.0), null),                              // no impact → excluded
        ];

        [$from, $to] = $this->window();
        $result = $this->service($meals)->forUser(new User(), $from, $to);

        self::assertCount(2, $result->aboveExpected);
        self::assertSame(2.2, $result->aboveExpected[0]->impact->ratio);
        self::assertSame(1.4, $result->aboveExpected[1]->impact->ratio);
    }

    public function testRankingsAreCappedAtTopN(): void
    {
        $meals = [];
        for ($i = 1; $i <= 8; $i++) {
            $meals[] = $this->meal(
                $this->response((float) ($i * 10), (float) ($i * 100)),
                $this->impact(1.5, 'higher'),
            );
        }

        [$from, $to] = $this->window();
        $result = $this->service($meals)->forUser(new User(), $from, $to);

        self::assertSame(8, $result->mealCount);
        self::assertCount(GlucoseInsightsService::TOP_N, $result->topSpikers);
        self::assertCount(GlucoseInsightsService::TOP_N, $result->gentlest);
        self::assertCount(GlucoseInsightsService::TOP_N, $result->aboveExpected);
    }

    public function testAiCoachAvailabilityRequiresBothGates(): void
    {
        [$from, $to] = $this->window();
        $user = new User();

        // Available only when BOTH the env kill switch AND the Loop+ feature are on
        // (the per-user admin toggle is retired — services follow the tier).
        self::assertFalse($this->service([])->forUser($user, $from, $to)->aiDeepDiveEnabled);
        self::assertFalse(
            $this->service([], aiEnabled: true, entitled: false)->forUser($user, $from, $to)->aiDeepDiveEnabled,
        );
        self::assertFalse($this->service([], entitled: false)->forUser($user, $from, $to)->aiDeepDiveEnabled);
        self::assertTrue($this->service([], aiEnabled: true)->forUser($user, $from, $to)->aiDeepDiveEnabled);

        // The service-level (env-only) accessor is unchanged.
        self::assertTrue($this->service([], aiEnabled: true)->isAiDeepDiveEnabled());
    }

    public function testCoachingNarrativeGeneratedWhenEntitledAndDataPresent(): void
    {
        $meals = [$this->meal($this->response(40.0, 1200.0), $this->impact(1.3, 'higher'))];

        $claude = $this->createMock(AiClient::class);
        $claude->expects(self::once())->method('complete')
            ->willReturn(new AiResult('Your dinners spike you most; try pairing rice with protein.', 300, 120));

        [$from, $to] = $this->window();
        $user = new User();
        $result = $this->service($meals, aiEnabled: true, claude: $claude)->forUser($user, $from, $to);

        self::assertSame('Your dinners spike you most; try pairing rice with protein.', $result->coachingNarrative);
        self::assertTrue($result->aiDeepDiveEnabled);
    }

    public function testUserTonePreferenceReachesTheCoachPrompt(): void
    {
        $meals = [$this->meal($this->response(40.0, 1200.0), $this->impact(1.3, 'higher'))];

        $captured = null;
        $claude = $this->createMock(AiClient::class);
        $claude->expects(self::once())->method('complete')
            ->with(
                self::identicalTo(AiFeature::GlucoseCoach),
                self::isInstanceOf(User::class),
                self::callback(static function (string $system) use (&$captured): bool {
                    $captured = $system;

                    return true;
                }),
                self::anything(),
                self::anything(),
            )
            ->willReturn(new AiResult('ok', 1, 1));

        [$from, $to] = $this->window();
        $user = (new User())->setToneHumor(5)->setToneWarmth(1);
        $this->service($meals, aiEnabled: true, claude: $claude)->forUser($user, $from, $to);

        // The base coach prompt and the user's tone dials both reach Claude.
        self::assertStringContainsString('Pattern Coach', (string) $captured);
        self::assertStringContainsString('improv comedian', (string) $captured);      // humor 5
        self::assertStringContainsString('clinical and neutral', (string) $captured); // warmth 1
        self::assertStringContainsString('not medical advice', (string) $captured);   // guardrail
    }

    public function testUserLocaleReachesTheCoachPromptAndRaisesTheTokenCap(): void
    {
        $meals = [$this->meal($this->response(40.0, 1200.0), $this->impact(1.3, 'higher'))];

        $captured = null;
        $tokens   = null;
        $claude = $this->createMock(AiClient::class);
        $claude->expects(self::once())->method('complete')
            ->with(
                self::identicalTo(AiFeature::GlucoseCoach),
                self::isInstanceOf(User::class),
                self::callback(static function (string $system) use (&$captured): bool {
                    $captured = $system;

                    return true;
                }),
                self::anything(),
                self::callback(static function (int $max) use (&$tokens): bool {
                    $tokens = $max;

                    return true;
                }),
            )
            ->willReturn(new AiResult('ok', 1, 1));

        [$from, $to] = $this->window();
        $user = new User();
        $user->setLocale(\App\Enum\SupportedLocale::Spanish);
        $this->service($meals, aiEnabled: true, claude: $claude)->forUser($user, $from, $to);

        self::assertStringContainsString('Respond in Spanish', (string) $captured);
        // Non-English narratives get ~30% token headroom (600 → 780) so the
        // closing disclaimer never truncates.
        self::assertSame(780, $tokens);
    }

    public function testSwitchingLanguageBustsTheNarrativeCache(): void
    {
        $meals = [$this->meal($this->response(40.0, 1200.0), $this->impact(1.3, 'higher'))];

        // Same user + data, but a language switch must NOT serve the cached
        // English narrative — the locale is part of the cache key.
        $claude = $this->createMock(AiClient::class);
        $claude->expects(self::exactly(2))->method('complete')
            ->willReturn(new AiResult('narrative', 1, 1));

        [$from, $to] = $this->window();
        $user    = new User();
        $service = $this->service($meals, aiEnabled: true, claude: $claude);

        $service->forUser($user, $from, $to);
        $user->setLocale(\App\Enum\SupportedLocale::Spanish);
        $service->forUser($user, $from, $to);
    }

    public function testCoachingNarrativeNullWhenClaudeFails(): void
    {
        $meals = [$this->meal($this->response(40.0, 1200.0), $this->impact(1.3, 'higher'))];

        $claude = $this->createStub(AiClient::class);
        $claude->method('complete')->willThrowException(new \RuntimeException('boom'));

        [$from, $to] = $this->window();
        $user = new User();
        $result = $this->service($meals, aiEnabled: true, claude: $claude)->forUser($user, $from, $to);

        // Degrades gracefully: still "available", but no narrative this load.
        self::assertNull($result->coachingNarrative);
        self::assertTrue($result->aiDeepDiveEnabled);
    }

    public function testCoachingNarrativeIsCachedAcrossViews(): void
    {
        $meals = [$this->meal($this->response(40.0, 1200.0), $this->impact(1.3, 'higher'))];

        // Same data + window across two page views → only one Claude call.
        $claude = $this->createMock(AiClient::class);
        $claude->expects(self::once())->method('complete')
            ->willReturn(new AiResult('Cached coaching narrative.', 300, 120));

        [$from, $to] = $this->window();
        $user    = new User();
        $service = $this->service($meals, aiEnabled: true, claude: $claude);

        $first  = $service->forUser($user, $from, $to);
        $second = $service->forUser($user, $from, $to);

        self::assertSame('Cached coaching narrative.', $first->coachingNarrative);
        self::assertSame('Cached coaching narrative.', $second->coachingNarrative);
    }

    public function testNoCoachingCallWhenUserNotEntitled(): void
    {
        $meals = [$this->meal($this->response(40.0, 1200.0), $this->impact(1.3, 'higher'))];

        $claude = $this->createMock(AiClient::class);
        $claude->expects(self::never())->method('complete');

        [$from, $to] = $this->window();
        // Env on, but the user lacks the Loop+ feature → no Claude call, no narrative.
        $result = $this->service($meals, aiEnabled: true, claude: $claude, entitled: false)
            ->forUser(new User(), $from, $to);

        self::assertNull($result->coachingNarrative);
        self::assertFalse($result->aiDeepDiveEnabled);
    }
}

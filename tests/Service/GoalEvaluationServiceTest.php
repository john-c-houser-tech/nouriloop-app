<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Ai\AiClient;
use App\Ai\AiFeature;
use App\Ai\AiResult;
use App\Dto\GoalDefinition;
use App\Entity\GoalCheckin;
use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalAggregation;
use App\Enum\GoalCheckinStatus;
use App\Enum\GoalDirection;
use App\Enum\GoalMetric;
use App\Enum\GoalOutputFormat;
use App\Service\Chart\GoalChartRenderer;
use App\Service\GoalEvaluationService;
use App\Service\GoalMetricCalculator;
use App\Service\LocalePromptBuilder;
use App\Service\TonePromptBuilder;
use App\Service\UserTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The deterministic side of evaluation — status + trend from the numbers, with the
 * Claude narrative skipped (generateNarrative: false). The metric calculator is
 * mocked so we feed exact current/previous values.
 */
final class GoalEvaluationServiceTest extends TestCase
{
    private function goal(GoalDefinition $def): NutritionGoal
    {
        // UTC user so the window math matches the UTC $now strings used below.
        return (new NutritionGoal())
            ->setUser((new User())->setTimezone('UTC'))
            ->setName('Test goal')
            ->setDefinition($def);
    }

    /**
     * Evaluates a goal with the metric calculator stubbed to return the given
     * aggregates in call order (current, then previous), narrative skipped.
     *
     * @param array<float|null> $aggregates
     */
    private function check(array $aggregates, GoalDefinition $def, ?\DateTimeImmutable $now = null): GoalCheckin
    {
        $calc = $this->createStub(GoalMetricCalculator::class);
        $calc->method('dailyValues')->willReturn([]);
        $i = 0;
        $calc->method('aggregate')->willReturnCallback(
            static function () use (&$i, $aggregates): ?float {
                return $aggregates[$i++] ?? null;
            },
        );

        $service = new GoalEvaluationService(
            $calc,
            $this->createStub(AiClient::class),
            $this->createStub(GoalChartRenderer::class),
            new NullLogger(),
            new UserTime(),
            new TonePromptBuilder(),
            new LocalePromptBuilder(new LocaleSwitcher('en', [])),
            self::catalogTranslator(),
        );

        return $service->evaluate($this->goal($def), $now ?? new \DateTimeImmutable(), false);
    }

    private function sugarAtMost30(): GoalDefinition
    {
        return new GoalDefinition(GoalMetric::Sugar, GoalDirection::AtMost, GoalAggregation::DailyAverage, 30.0, 7);
    }

    public function testAchievedWhenTargetMet(): void
    {
        $checkin = $this->check([25.0, 28.0], $this->sugarAtMost30());

        self::assertSame(GoalCheckinStatus::Achieved, $checkin->getStatus());
        self::assertSame(25.0, $checkin->getCurrentValue());
        self::assertSame(30.0, $checkin->getTargetValue());
        self::assertTrue($checkin->getStatus()->isDeliverable());
    }

    public function testOnTrackWhenImprovingTowardTarget(): void
    {
        // Over target (35 > 30) but down from 40 → moving the right way.
        $checkin = $this->check([35.0, 40.0], $this->sugarAtMost30());

        self::assertSame(GoalCheckinStatus::OnTrack, $checkin->getStatus());
        self::assertStringContainsStringIgnoringCase('improving', $checkin->getSummaryText() ?? '');
        // The trend key is persisted so displays can explain WHY this is on track.
        self::assertSame('improving', $checkin->getTrend());
    }

    public function testOffTrackWhenSlippingAwayFromTarget(): void
    {
        // Over target and rising (32 → 35).
        $checkin = $this->check([35.0, 32.0], $this->sugarAtMost30());

        self::assertSame(GoalCheckinStatus::OffTrack, $checkin->getStatus());
        self::assertSame('slipping', $checkin->getTrend());
    }

    public function testFirstCheckinNotMetIsOnTrack(): void
    {
        // No previous period (prev aggregate null) → neutral OnTrack.
        $checkin = $this->check([35.0, null], $this->sugarAtMost30());

        self::assertSame(GoalCheckinStatus::OnTrack, $checkin->getStatus());
        self::assertSame('first', $checkin->getTrend());
    }

    public function testNoDataWhenNothingLogged(): void
    {
        $checkin = $this->check([null], $this->sugarAtMost30());

        self::assertSame(GoalCheckinStatus::NoData, $checkin->getStatus());
        self::assertNull($checkin->getCurrentValue());
        self::assertNull($checkin->getTrend());
        self::assertStringContainsString('No meals were logged', $checkin->getSummaryText() ?? '');
        self::assertSame(0, $checkin->totalTokens());
        // No-data check-ins are recorded but not emailed (no nag-spam).
        self::assertFalse($checkin->getStatus()->isDeliverable());
    }

    public function testWindowCoversCompleteDaysExcludingToday(): void
    {
        // Run mid-afternoon; the 7-day window must be whole days ending at last
        // midnight (today excluded so its partial day can't bias the average).
        $now = new \DateTimeImmutable('2026-06-14 18:30:00', new \DateTimeZone('UTC'));
        $checkin = $this->check([25.0, 28.0], $this->sugarAtMost30(), $now);

        self::assertSame('2026-06-14 00:00:00', $checkin->getWindowEnd()->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-07 00:00:00', $checkin->getWindowStart()->format('Y-m-d H:i:s'));
    }

    /**
     * The chart plots a 30-day trend, not the (here 1-day) evaluation window, so a
     * daily goal's chart isn't a single point. We assert the calculator is queried
     * over a 30-day span and the renderer gets that wider, >= 2-point series.
     */
    public function testChartUsesA30DayTrendWindow(): void
    {
        $def = new GoalDefinition(GoalMetric::Sugar, GoalDirection::AtLeast, GoalAggregation::DailyAverage, 15.0, 1);
        $now = new \DateTimeImmutable('2026-06-23 12:00:00', new \DateTimeZone('UTC'));

        $spans = [];
        $calc = $this->createStub(GoalMetricCalculator::class);
        $calc->method('dailyValues')->willReturnCallback(
            static function ($u, $m, \DateTimeImmutable $from, \DateTimeImmutable $to) use (&$spans): array {
                $days = (int) $from->diff($to)->days;
                $spans[] = $days;

                // The 30-day chart query returns a multi-day series; the 1-day
                // evaluation/previous windows return a single day.
                return $days >= 30
                    ? ['d1' => 16.0, 'd2' => 14.0, 'd3' => 18.0, 'd4' => 15.0]
                    : ['2026-06-22' => 20.0];
            },
        );
        $calc->method('aggregate')->willReturn(20.0);

        $claude = $this->createStub(AiClient::class);
        $claude->method('complete')->willReturn(new AiResult('Nice work.', 1, 1));

        $renderer = $this->createMock(GoalChartRenderer::class);
        $renderer->expects(self::once())->method('render')
            ->with(
                self::anything(),
                self::callback(static fn (array $series): bool => count($series) >= 2),
                self::anything(),
            )
            ->willReturn('goal-charts/x.png');

        $service = new GoalEvaluationService(
            $calc,
            $claude,
            $renderer,
            new NullLogger(),
            new UserTime(),
            new TonePromptBuilder(),
            new LocalePromptBuilder(new LocaleSwitcher('en', [])),
            self::catalogTranslator(),
        );
        $goal = $this->goal($def)->setOutputFormat(GoalOutputFormat::Chart);

        $checkin = $service->evaluate($goal, $now, true);

        self::assertContains(30, $spans, 'chart should query a 30-day window');
        self::assertSame('goal-charts/x.png', $checkin->getChartPath());
    }

    public function testNarrativePromptCarriesTheOwnersLocaleAndRaisedTokenCap(): void
    {
        $def = new GoalDefinition(GoalMetric::Sugar, GoalDirection::AtMost, GoalAggregation::DailyAverage, 30.0, 7);

        $calc = $this->createStub(GoalMetricCalculator::class);
        $calc->method('dailyValues')->willReturn(['2026-06-22' => 20.0, '2026-06-23' => 25.0]);
        $calc->method('aggregate')->willReturn(22.0);

        $system = null;
        $tokens = null;
        $claude = $this->createMock(AiClient::class);
        $claude->expects(self::once())->method('complete')
            ->with(
                self::identicalTo(AiFeature::GoalCheckin),
                self::isInstanceOf(User::class),
                self::callback(static function (string $s) use (&$system): bool {
                    $system = $s;

                    return true;
                }),
                self::anything(),
                self::callback(static function (int $t) use (&$tokens): bool {
                    $tokens = $t;

                    return true;
                }),
            )
            ->willReturn(new AiResult('Buen trabajo.', 1, 1));

        $service = new GoalEvaluationService(
            $calc,
            $claude,
            $this->createStub(GoalChartRenderer::class),
            new NullLogger(),
            new UserTime(),
            new TonePromptBuilder(),
            new LocalePromptBuilder(new LocaleSwitcher('en', [])),
            self::catalogTranslator(),
        );

        $goal = $this->goal($def);
        $goal->getUser()->setLocale(\App\Enum\SupportedLocale::Spanish);
        $goal->setEvaluationPromptTemplate('Summarize {{current_value}} vs {{target}}.');

        $service->evaluate($goal, new \DateTimeImmutable('2026-06-24 12:00:00', new \DateTimeZone('UTC')), true);

        self::assertStringContainsString('Respond in Spanish', (string) $system);
        // The English base guardrails still lead the prompt…
        self::assertStringContainsString('not medical advice', (string) $system);
        // …and non-English narratives get ~30% headroom (512 → 666).
        self::assertSame(666, $tokens);
    }

    /**
     * The system prompt must define the status vocabulary — without it, Claude reads
     * "On track" as "target met", sees an under-target current value, and hedges about
     * the "contradiction" in the narrative (hit in prod 2026-07-23 on an at_least fiber
     * goal: 10 g vs. 15 g, status On track). The glossary lives in the system prompt —
     * not the per-goal template — so it covers existing goals and user-edited templates.
     */
    public function testNarrativeSystemPromptDefinesTheStatusVocabulary(): void
    {
        $def = new GoalDefinition(GoalMetric::Fiber, GoalDirection::AtLeast, GoalAggregation::DailyAverage, 15.0, 7);

        $calc = $this->createStub(GoalMetricCalculator::class);
        $calc->method('dailyValues')->willReturn(['2026-07-22' => 10.0]);
        $i = 0;
        $calc->method('aggregate')->willReturnCallback(
            static function () use (&$i): ?float {
                return [10.0, 8.0][$i++] ?? null; // under target but improving → OnTrack
            },
        );

        $system = null;
        $claude = $this->createMock(AiClient::class);
        $claude->expects(self::once())->method('complete')
            ->with(
                self::identicalTo(AiFeature::GoalCheckin),
                self::isInstanceOf(User::class),
                self::callback(static function (string $s) use (&$system): bool {
                    $system = $s;

                    return true;
                }),
                self::anything(),
                self::anything(),
            )
            ->willReturn(new AiResult('Solid progress.', 1, 1));

        $service = new GoalEvaluationService(
            $calc,
            $claude,
            $this->createStub(GoalChartRenderer::class),
            new NullLogger(),
            new UserTime(),
            new TonePromptBuilder(),
            new LocalePromptBuilder(new LocaleSwitcher('en', [])),
            self::catalogTranslator(),
        );

        $checkin = $service->evaluate($this->goal($def), new \DateTimeImmutable(), true);

        self::assertSame(GoalCheckinStatus::OnTrack, $checkin->getStatus());
        $system = (string) $system;
        self::assertStringContainsString('"On track" means the target was NOT met yet', $system);
        self::assertStringContainsString('expected, not a contradiction', $system);
        self::assertStringContainsString('Never question, second-guess, or reinterpret the status', $system);
        // The pre-existing guardrails must survive the insertion.
        self::assertStringContainsString('supportive nutrition coach', $system);
        self::assertStringContainsString('plain prose', $system);
        self::assertStringContainsString('not medical advice', $system);
    }

    public function testChartSkippedWhenFewerThanTwoPoints(): void
    {
        $def = new GoalDefinition(GoalMetric::Sugar, GoalDirection::AtLeast, GoalAggregation::DailyAverage, 15.0, 1);

        $calc = $this->createStub(GoalMetricCalculator::class);
        // Even the 30-day window yields a single logged day → no useful chart.
        $calc->method('dailyValues')->willReturn(['2026-06-22' => 20.0]);
        $calc->method('aggregate')->willReturn(20.0);

        $claude = $this->createStub(AiClient::class);
        $claude->method('complete')->willReturn(new AiResult('Nice work.', 1, 1));

        $renderer = $this->createMock(GoalChartRenderer::class);
        $renderer->expects(self::never())->method('render');

        $service = new GoalEvaluationService(
            $calc,
            $claude,
            $renderer,
            new NullLogger(),
            new UserTime(),
            new TonePromptBuilder(),
            new LocalePromptBuilder(new LocaleSwitcher('en', [])),
            self::catalogTranslator(),
        );
        $goal = $this->goal($def)->setOutputFormat(GoalOutputFormat::Chart);

        $now = new \DateTimeImmutable('2026-06-23 12:00:00', new \DateTimeZone('UTC'));
        $checkin = $service->evaluate($goal, $now, true);

        self::assertNull($checkin->getChartPath());
    }

    public function testMaintainRangeAchievedInsideBand(): void
    {
        $def = new GoalDefinition(
            GoalMetric::Calories,
            GoalDirection::Maintain,
            GoalAggregation::DailyAverage,
            2000.0,
            7,
            targetMax: 2200.0,
        );

        $checkin = $this->check([2100.0, 2400.0], $def);

        self::assertSame(GoalCheckinStatus::Achieved, $checkin->getStatus());
    }

    public function testClaudeFailureFallbackIsInTheOwnersLocale(): void
    {
        // The cron evaluates BEFORE the delivery dispatcher's runWithLocale
        // wrap, so the stored fallback summary must already be localized.
        $def = $this->sugarAtMost30();

        $calc = $this->createStub(GoalMetricCalculator::class);
        $calc->method('dailyValues')->willReturn(['2026-06-22' => 25.0]);
        $i = 0;
        $calc->method('aggregate')->willReturnCallback(
            static function () use (&$i): ?float {
                return [25.0, 28.0][$i++] ?? null;
            },
        );

        $claude = $this->createStub(AiClient::class);
        $claude->method('complete')->willThrowException(new \RuntimeException('outage'));

        $service = new GoalEvaluationService(
            $calc,
            $claude,
            $this->createStub(GoalChartRenderer::class),
            new NullLogger(),
            new UserTime(),
            new TonePromptBuilder(),
            new LocalePromptBuilder(new LocaleSwitcher('en', [])),
            self::catalogTranslator(),
        );

        $goal = $this->goal($def);
        $goal->getUser()->setLocale(\App\Enum\SupportedLocale::Spanish);

        $checkin = $service->evaluate($goal, new \DateTimeImmutable(), true);

        $summary = (string) $checkin->getSummaryText();
        self::assertStringContainsString('Meta lograda', $summary);
        self::assertStringContainsString('frente a un objetivo de', $summary);
        self::assertStringContainsString('Mejorando', $summary);
    }

    /** A real translator over the committed en + es catalogs, like the delivery pipeline uses. */
    private static function catalogTranslator(): \Symfony\Component\Translation\Translator
    {
        $translator = new \Symfony\Component\Translation\Translator('en');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('yaml', new \Symfony\Component\Translation\Loader\YamlFileLoader());
        foreach (['en', 'es'] as $locale) {
            $translator->addResource(
                'yaml',
                __DIR__ . '/../../translations/messages+intl-icu.' . $locale . '.yaml',
                $locale,
                'messages+intl-icu',
            );
        }

        return $translator;
    }
}

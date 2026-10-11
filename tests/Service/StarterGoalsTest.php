<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\GoalAggregation;
use App\Enum\GoalDirection;
use App\Enum\GoalMetric;
use App\Service\DietaryReference;
use App\Service\GoalMetricCalculator;
use App\Service\GoalSafetyValidator;
use App\Service\StarterGoals;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * The one-click starter-goal templates: personalization rules (DV when no
 * history, nudged by the baseline otherwise, clamped away from silly or unsafe
 * targets) and the by-construction guarantee that no starter definition ever
 * trips a GoalSafetyValidator warning — the one-click path has no acknowledge
 * step, so this must hold for any baseline.
 */
final class StarterGoalsTest extends TestCase
{
    public function testNoHistoryFallsBackToDailyValues(): void
    {
        $starters = $this->starters(null);

        $fiber = $starters->definitionFor($this->user(), 'fiber');
        self::assertSame(GoalMetric::Fiber, $fiber->metric);
        self::assertSame(GoalDirection::AtLeast, $fiber->direction);
        self::assertSame(GoalAggregation::DailyAverage, $fiber->aggregation);
        self::assertSame(28.0, $fiber->targetValue);
        self::assertSame(7, $fiber->evaluationWindowDays);

        self::assertSame(50.0, $starters->definitionFor($this->user(), 'protein')->targetValue);
        self::assertSame(50.0, $starters->definitionFor($this->user(), 'sugar')->targetValue);
        self::assertSame(2000.0, $starters->definitionFor($this->user(), 'calories')->targetValue);
    }

    public function testAtLeastTargetRisesAboveAnAlreadyHighBaseline(): void
    {
        // Fiber baseline 35 g ≥ the 28 g DV → aim ~10% above their own average.
        $def = $this->starters(35.0)->definitionFor($this->user(), 'fiber');
        self::assertSame(round(35.0 * 1.1), $def->targetValue);
    }

    public function testAtLeastTargetIsCappedAgainstGarbageBaselines(): void
    {
        // A bad/sparse AI-derived baseline must never one-click an absurd goal:
        // at-least targets cap at 2× the DV (fiber 56 g, protein 100 g).
        self::assertSame(56.0, $this->starters(10000.0)->definitionFor($this->user(), 'fiber')->targetValue);
        self::assertSame(100.0, $this->starters(10000.0)->definitionFor($this->user(), 'protein')->targetValue);
        // At the boundary the uncapped rule still wins (50.9 × 1.1 = 56).
        self::assertSame(56.0, $this->starters(50.9)->definitionFor($this->user(), 'fiber')->targetValue);
    }

    public function testSugarTightensBelowDvButIsFloored(): void
    {
        // Already under the DV → ~90% of their average…
        self::assertSame(27.0, $this->starters(30.0)->definitionFor($this->user(), 'sugar')->targetValue);
        // …but a sparse day-1 history can't produce an absurd target.
        self::assertSame(25.0, $this->starters(3.0)->definitionFor($this->user(), 'sugar')->targetValue);
    }

    public function testCaloriesTrackTheBaselineWithinClamps(): void
    {
        // 5% under the average, rounded to 50.
        self::assertSame(2450.0, $this->starters(2600.0)->definitionFor($this->user(), 'calories')->targetValue);
        // A low (sparse-history) average clamps well above the 1,200 safety floor.
        self::assertSame(1500.0, $this->starters(900.0)->definitionFor($this->user(), 'calories')->targetValue);
        // And a huge average clamps at the top.
        self::assertSame(3500.0, $this->starters(6000.0)->definitionFor($this->user(), 'calories')->targetValue);
    }

    public function testUnknownTemplateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->starters(null)->definitionFor($this->user(), 'world-peace');
    }

    /**
     * Every template must produce a warning-free definition for any baseline —
     * the starter path never shows the acknowledge-warnings step.
     */
    public function testEveryTemplateIsSafeByConstructionForAnyBaseline(): void
    {
        $validator = new GoalSafetyValidator($this->enTranslator());

        foreach ([null, 1.0, 10.0, 100.0, 900.0, 3000.0, 10000.0, 1000000.0] as $baseline) {
            foreach (StarterGoals::TEMPLATES as $template) {
                $def = $this->starters($baseline)->definitionFor($this->user(), $template);
                self::assertSame(
                    [],
                    $validator->validate($def),
                    sprintf('template "%s" with baseline %s must produce no warnings', $template, $baseline ?? 'null'),
                );
            }
        }
    }

    private function starters(?float $baseline): StarterGoals
    {
        $calculator = $this->createStub(GoalMetricCalculator::class);
        $calculator->method('baseline')->willReturn($baseline);

        return new StarterGoals(new DietaryReference(), $calculator);
    }

    private function user(): \App\Entity\User
    {
        return new \App\Entity\User();
    }

    private function enTranslator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource(
            'yaml',
            __DIR__ . '/../../translations/messages+intl-icu.en.yaml',
            'en',
            'messages+intl-icu',
        );

        return $translator;
    }
}

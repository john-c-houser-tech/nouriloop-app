<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Dto\GoalDefinition;
use App\Entity\GoalCheckin;
use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalAggregation;
use App\Enum\GoalCheckinStatus;
use App\Enum\GoalDirection;
use App\Enum\GoalMetric;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The check-in email's "On track" clarification (goal.status.on_track_hint_*):
 * "On track" deliberately covers "not met yet but improving" and "first check-in",
 * so the badge must say why — otherwise 10 g next to an at-least-15 g target reads
 * as a mislabel (hit in prod 2026-07-23). Renders the real Twig template through
 * the real catalogs, pinning the direction-aware ICU select and the guards (no
 * hint on achieved, no hint on legacy rows with a null trend).
 */
final class GoalCheckinEmailHintTest extends WebTestCase
{
    public function testAtLeastOnTrackImprovingSaysBelowTargetTrendingUp(): void
    {
        $html = $this->render(GoalDirection::AtLeast, GoalCheckinStatus::OnTrack, 'improving');

        self::assertStringContainsString('Below target, trending up', $html);
    }

    public function testAtMostOnTrackImprovingSaysAboveTargetTrendingDown(): void
    {
        $html = $this->render(GoalDirection::AtMost, GoalCheckinStatus::OnTrack, 'improving');

        self::assertStringContainsString('Above target, trending down', $html);
    }

    public function testFirstCheckinOnTrackSaysNothingToCompareYet(): void
    {
        $html = $this->render(GoalDirection::AtLeast, GoalCheckinStatus::OnTrack, 'first');

        self::assertStringContainsString('First check-in', $html);
        self::assertStringNotContainsString('Below target', $html);
    }

    public function testAchievedGetsNoHint(): void
    {
        $html = $this->render(GoalDirection::AtLeast, GoalCheckinStatus::Achieved, 'improving');

        self::assertStringNotContainsString('Below target', $html);
        self::assertStringNotContainsString('First check-in', $html);
    }

    public function testLegacyCheckinWithoutTrendGetsNoHint(): void
    {
        // Rows written before the trend column exist with trend = null.
        $html = $this->render(GoalDirection::AtLeast, GoalCheckinStatus::OnTrack, null);

        self::assertStringNotContainsString('Below target', $html);
        self::assertStringNotContainsString('First check-in', $html);
    }

    private function render(GoalDirection $direction, GoalCheckinStatus $status, ?string $trend): string
    {
        self::bootKernel();

        $def = new GoalDefinition(GoalMetric::Fiber, $direction, GoalAggregation::DailyAverage, 15.0, 7);
        $user = (new User())
            ->setEmail('hint@test.local')
            ->setDisplayName('Hint Tester')
            ->setTimezone('UTC');
        $goal = (new NutritionGoal())
            ->setUser($user)
            ->setName('Boost Daily Fiber')
            ->setDefinition($def);
        $checkin = (new GoalCheckin())
            ->setGoal($goal)
            ->setStatus($status)
            ->setTrend($trend)
            ->setCurrentValue(10.0)
            ->setTargetValue(15.0)
            ->setSummaryText('Steady progress this week.');

        return static::getContainer()->get('twig')->render('email/goal_checkin.html.twig', [
            'user'     => $user,
            'goal'     => $goal,
            'def'      => $def,
            'checkin'  => $checkin,
            'url'      => 'https://nouriloop.com/goals/1',
            'hasChart' => false,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Pure (DB-less) coverage of the onboarding state machine on User:
 * needsOnboarding() gating and markOnboardingStep() completion/idempotence.
 */
final class UserOnboardingTest extends TestCase
{
    public function testFreshUserNeedsOnboarding(): void
    {
        $user = new User();

        self::assertTrue($user->needsOnboarding());
        self::assertNull($user->getOnboardingCompletedAt());
        self::assertFalse($user->isOnboardingDismissed());
    }

    public function testCompletedAtIsSetOnlyWhenAllThreeRequiredStepsDone(): void
    {
        $user = new User();
        $at = new \DateTimeImmutable('2026-06-28 10:00:00');

        $user->markOnboardingStep('meal', $at);
        $user->markOnboardingStep('goal', $at);
        // Health is optional and must not complete onboarding on its own.
        $user->markOnboardingStep('health', $at);
        self::assertNull($user->getOnboardingCompletedAt());
        self::assertTrue($user->needsOnboarding());

        $user->markOnboardingStep('notifications', $at);
        self::assertNotNull($user->getOnboardingCompletedAt());
        self::assertFalse($user->needsOnboarding());
    }

    public function testOverlayStaysThroughOptionalStepUntilHealthResolved(): void
    {
        $user = new User();
        $at = new \DateTimeImmutable();

        $user->markOnboardingStep('meal', $at);
        $user->markOnboardingStep('goal', $at);
        $user->markOnboardingStep('notifications', $at);
        // Required done — but the optional Extra credit step keeps the overlay open.
        self::assertNotNull($user->getOnboardingCompletedAt());
        self::assertFalse($user->allOnboardingStepsResolved());
        self::assertTrue($user->needsOnboarding());

        // Skipping/doing health resolves the last step and closes the overlay.
        $user->markOnboardingStep('health', $at);
        self::assertTrue($user->allOnboardingStepsResolved());
        self::assertFalse($user->needsOnboarding());
    }

    public function testHealthAloneNeverCompletes(): void
    {
        $user = new User();
        $user->markOnboardingStep('health', new \DateTimeImmutable());

        self::assertNotNull($user->getOnboardingHealthAt());
        self::assertNull($user->getOnboardingCompletedAt());
        self::assertTrue($user->needsOnboarding());
    }

    public function testStepTimestampIsStableAcrossRepeats(): void
    {
        $user = new User();
        $first = new \DateTimeImmutable('2026-06-28 10:00:00');
        $later = new \DateTimeImmutable('2026-06-29 10:00:00');

        $user->markOnboardingStep('meal', $first);
        $user->markOnboardingStep('meal', $later);

        self::assertEquals($first, $user->getOnboardingMealAt());
    }

    public function testDismissOverridesActiveOnboarding(): void
    {
        $user = new User();
        self::assertTrue($user->needsOnboarding());

        $user->setOnboardingDismissed(true);
        self::assertFalse($user->needsOnboarding());
    }

    public function testUnknownStepThrows(): void
    {
        $this->expectException(\UnhandledMatchError::class);
        (new User())->markOnboardingStep('bogus', new \DateTimeImmutable());
    }
}

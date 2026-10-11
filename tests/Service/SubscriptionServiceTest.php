<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use App\Service\SubscriptionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SubscriptionServiceTest extends TestCase
{
    private const NOW = '2026-06-22 12:00:00';

    public function testFreshAccountIsFreeAndInactive(): void
    {
        $service = $this->service();
        $user = new User(); // defaults: tier Free, status null

        self::assertFalse($service->isSubscriptionActive($user));
        self::assertSame(SubscriptionTier::Free, $service->effectiveTier($user));
        self::assertFalse($service->hasFeature($user, 'unlimited_checkins'));
        self::assertFalse($service->hasFeature($user, 'sms_digest'));
    }

    public function testActiveSubscriptionGrantsItsTier(): void
    {
        $service = $this->service();
        $user = (new User())
            ->setSubscriptionTier(SubscriptionTier::LoopPlus)
            ->setSubscriptionStatus('active');

        self::assertTrue($service->isSubscriptionActive($user));
        self::assertSame(SubscriptionTier::LoopPlus, $service->effectiveTier($user));
        self::assertTrue($service->hasFeature($user, 'cgm_correlation'));
    }

    public function testTrialingCountsAsActive(): void
    {
        $service = $this->service();
        $user = (new User())
            ->setSubscriptionTier(SubscriptionTier::Loop)
            ->setSubscriptionStatus('trialing');

        self::assertTrue($service->isSubscriptionActive($user));
        self::assertTrue($service->hasFeature($user, 'unlimited_photos'));
    }

    public function testCanceledWithinGracePeriodKeepsAccess(): void
    {
        $service = $this->service();
        $user = (new User())
            ->setSubscriptionTier(SubscriptionTier::LoopPlus)
            ->setSubscriptionStatus('canceled')
            ->setSubscriptionPeriodEnd(new \DateTimeImmutable('2026-06-25 00:00:00')); // future

        self::assertTrue($service->isSubscriptionActive($user));
        self::assertSame(SubscriptionTier::LoopPlus, $service->effectiveTier($user));
    }

    public function testCanceledAfterPeriodEndLazilyDowngradesToFree(): void
    {
        $service = $this->service();
        $user = (new User())
            ->setSubscriptionTier(SubscriptionTier::LoopPlus)
            ->setSubscriptionStatus('canceled')
            ->setSubscriptionPeriodEnd(new \DateTimeImmutable('2026-06-20 00:00:00')); // past

        self::assertFalse($service->isSubscriptionActive($user));
        self::assertSame(SubscriptionTier::Free, $service->effectiveTier($user));
        self::assertFalse($service->hasFeature($user, 'ai_pattern_coach'));
    }

    public function testPastDueWithinGracePeriodKeepsAccess(): void
    {
        $service = $this->service();
        $user = (new User())
            ->setSubscriptionTier(SubscriptionTier::Loop)
            ->setSubscriptionStatus('past_due')
            ->setSubscriptionPeriodEnd(new \DateTimeImmutable('2026-06-23 00:00:00')); // future

        self::assertTrue($service->isSubscriptionActive($user));
        self::assertSame(SubscriptionTier::Loop, $service->effectiveTier($user));
    }

    public function testCanceledWithNoPeriodEndIsInactive(): void
    {
        $service = $this->service();
        $user = (new User())
            ->setSubscriptionTier(SubscriptionTier::Loop)
            ->setSubscriptionStatus('canceled');

        self::assertFalse($service->isSubscriptionActive($user));
        self::assertSame(SubscriptionTier::Free, $service->effectiveTier($user));
    }

    public function testIncompleteStatusIsInactive(): void
    {
        $service = $this->service();
        $user = (new User())
            ->setSubscriptionTier(SubscriptionTier::Loop)
            ->setSubscriptionStatus('incomplete');

        self::assertFalse($service->isSubscriptionActive($user));
        self::assertSame(SubscriptionTier::Free, $service->effectiveTier($user));
    }

    public function testPermanentCompOverridesEverything(): void
    {
        $service = $this->service();
        $user = (new User())
            ->setCompTier(SubscriptionTier::LoopPlus)
            ->setCompExpiresAt(null); // permanent

        self::assertTrue($service->hasActiveComp($user));
        self::assertSame(SubscriptionTier::LoopPlus, $service->effectiveTier($user));
        self::assertTrue($service->hasFeature($user, 'ai_pattern_coach'));
    }

    public function testTimeLimitedCompGrantsUntilItExpires(): void
    {
        $service = $this->service();
        $user = (new User())
            ->setCompTier(SubscriptionTier::LoopPlus)
            ->setCompExpiresAt(new \DateTimeImmutable('2026-06-25 00:00:00')); // future

        self::assertTrue($service->hasActiveComp($user));
        self::assertSame(SubscriptionTier::LoopPlus, $service->effectiveTier($user));
    }

    public function testExpiredCompFallsThroughToFree(): void
    {
        $service = $this->service();
        $user = (new User())
            ->setCompTier(SubscriptionTier::LoopPlus)
            ->setCompExpiresAt(new \DateTimeImmutable('2026-06-20 00:00:00')); // past, no Stripe sub

        self::assertFalse($service->hasActiveComp($user));
        self::assertSame(SubscriptionTier::Free, $service->effectiveTier($user));
    }

    public function testExpiredCompFallsThroughToActiveStripeSub(): void
    {
        $service = $this->service();
        $user = (new User())
            ->setCompTier(SubscriptionTier::LoopPlus)
            ->setCompExpiresAt(new \DateTimeImmutable('2026-06-20 00:00:00')) // past
            ->setSubscriptionTier(SubscriptionTier::Loop)
            ->setSubscriptionStatus('active');

        // Comp lapsed, but they bought Loop in the meantime — that takes over.
        self::assertSame(SubscriptionTier::Loop, $service->effectiveTier($user));
    }

    public function testActiveCompBeatsALowerPaidSub(): void
    {
        $service = $this->service();
        $user = (new User())
            ->setCompTier(SubscriptionTier::LoopPlus)
            ->setCompExpiresAt(null)
            ->setSubscriptionTier(SubscriptionTier::Loop)
            ->setSubscriptionStatus('active');

        self::assertSame(SubscriptionTier::LoopPlus, $service->effectiveTier($user));
    }

    public function testHigherPaidSubBeatsALowerComp(): void
    {
        // A comp must never *downgrade* someone who pays for a higher tier — the
        // effective tier is the max of all active entitlements.
        $service = $this->service();
        $user = (new User())
            ->setCompTier(SubscriptionTier::Loop)
            ->setCompExpiresAt(null)
            ->setSubscriptionTier(SubscriptionTier::LoopPlus)
            ->setSubscriptionStatus('active');

        self::assertSame(SubscriptionTier::LoopPlus, $service->effectiveTier($user));
        self::assertTrue($service->hasFeature($user, 'ai_pattern_coach'));
    }

    /**
     * The clock is fixed here, so this pins the exact instant rather than a
     * tolerance — and deriving it from the constant means shortening or
     * lengthening the comp updates the expectation with it, while the
     * hard-coded upper bound below still objects if it grows back toward a
     * year (see the constant's docblock for why the length is load-bearing).
     */
    public function testGrantBetaTesterCompSetsATimeLimitedLoopPlus(): void
    {
        $service = $this->service();
        $user = new User();

        $service->grantBetaTesterComp($user);

        self::assertSame(SubscriptionTier::LoopPlus, $user->getCompTier());
        $expected = (new \DateTimeImmutable(self::NOW))
            ->modify(SubscriptionService::BETA_COMP_DURATION);
        self::assertEquals($expected, $user->getCompExpiresAt());
        self::assertTrue($service->hasActiveComp($user));

        self::assertLessThanOrEqual(
            90,
            (int) (new \DateTimeImmutable(self::NOW))->diff($expected)->days,
            'A beta comp longer than ~90 days stops being a willingness-to-pay signal.',
        );
    }

    public function testGrantCompPermanentLeavesNoExpiry(): void
    {
        $service = $this->service();
        $user = new User();

        $service->grantComp($user, SubscriptionTier::LoopPlus, null);

        self::assertSame(SubscriptionTier::LoopPlus, $user->getCompTier());
        self::assertNull($user->getCompExpiresAt());
    }

    private function service(): SubscriptionService
    {
        return new SubscriptionService(new MockClock(new \DateTimeImmutable(self::NOW)));
    }
}

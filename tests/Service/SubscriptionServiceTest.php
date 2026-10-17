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
        self::assertFalse($service->hasFeature($user, 'sms'));
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
        self::assertTrue($service->hasFeature($user, 'unlimited_checkins'));
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

    /**
     * Who changes plan in the portal rather than through a second Checkout: anyone whose
     * Stripe subscription still exists and can bill. A comp alone is not a subscription.
     */
    public function testHasLiveSubscriptionFollowsTheStripeStatus(): void
    {
        $service = $this->service();
        foreach (['active', 'trialing', 'past_due', 'unpaid', 'incomplete'] as $status) {
            $user = (new User())->setStripeSubscriptionId('sub_1')->setSubscriptionStatus($status);
            self::assertTrue($service->hasLiveSubscription($user), $status);
        }
        foreach (['canceled', 'incomplete_expired', null] as $status) {
            $user = (new User())->setStripeSubscriptionId('sub_1')->setSubscriptionStatus($status);
            self::assertFalse($service->hasLiveSubscription($user), (string) $status);
        }

        $comped = (new User())->setCompTier(SubscriptionTier::LoopPlus)->setSubscriptionStatus('active');
        self::assertFalse($service->hasLiveSubscription($comped), 'no Stripe subscription id');
    }

    /** The plan Stripe bills, for the pricing page; a comp lifts only the effective tier. */
    public function testBilledTierIgnoresAComp(): void
    {
        $service = $this->service();
        $user = (new User())
            ->setStripeSubscriptionId('sub_1')
            ->setSubscriptionTier(SubscriptionTier::Loop)
            ->setSubscriptionStatus('active')
            ->setCompTier(SubscriptionTier::LoopPlus);

        self::assertSame(SubscriptionTier::Loop, $service->billedTier($user));
        self::assertSame(SubscriptionTier::LoopPlus, $service->effectiveTier($user));

        $user->setSubscriptionStatus('canceled');
        self::assertNull($service->billedTier($user));
    }

    /** The date Settings shows for a canceled plan, and when it shows none. */
    public function testScheduledEnd(): void
    {
        $service = $this->service();
        $ends = new \DateTimeImmutable('2026-07-13 12:00:00');

        $scheduled = (new User())->setStripeSubscriptionId('sub_1')->setSubscriptionStatus('active')
            ->setSubscriptionPeriodEnd($ends)->setSubscriptionCancelAt($ends);
        self::assertEquals($ends, $service->scheduledEnd($scheduled));

        $renewing = (new User())->setStripeSubscriptionId('sub_1')->setSubscriptionStatus('active')
            ->setSubscriptionPeriodEnd($ends);
        self::assertNull($service->scheduledEnd($renewing), 'nothing scheduled');

        $grace = (new User())->setStripeSubscriptionId('sub_1')->setSubscriptionStatus('canceled')
            ->setSubscriptionPeriodEnd($ends);
        self::assertEquals($ends, $service->scheduledEnd($grace), 'canceled, paid period still running');

        $lapsed = (new User())->setStripeSubscriptionId('sub_1')->setSubscriptionStatus('canceled')
            ->setSubscriptionPeriodEnd(new \DateTimeImmutable('2026-06-01'));
        self::assertNull($service->scheduledEnd($lapsed), 'already ended');
    }

    private function service(): SubscriptionService
    {
        return new SubscriptionService(new MockClock(new \DateTimeImmutable(self::NOW)));
    }
}

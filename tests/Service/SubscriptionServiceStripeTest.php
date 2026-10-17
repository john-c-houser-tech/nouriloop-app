<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use App\Service\SubscriptionService;
use PHPUnit\Framework\TestCase;
use Stripe\Subscription;
use Symfony\Component\Clock\MockClock;

/**
 * Covers the Stripe-facing mapping helpers that don't need the SDK client: price→tier
 * resolution and syncing a Subscription object onto the user's columns.
 */
final class SubscriptionServiceStripeTest extends TestCase
{
    private const LOOP_M = 'price_loop_monthly';
    private const LOOPP_M = 'price_loop_plus_monthly';

    public function testTierForPriceIdMapsKnownPrices(): void
    {
        $service = $this->service();

        self::assertSame(SubscriptionTier::Loop, $service->tierForPriceId(self::LOOP_M));
        self::assertSame(SubscriptionTier::LoopPlus, $service->tierForPriceId(self::LOOPP_M));
    }

    public function testUnknownAndEmptyPriceIdsAreNotResolved(): void
    {
        $service = $this->service();

        self::assertNull($service->tierForPriceId('price_unknown'));
        self::assertNull($service->tierForPriceId(''));
        self::assertFalse($service->isKnownPriceId('price_unknown'));
        self::assertTrue($service->isKnownPriceId(self::LOOPP_M));
        // The retired yearly prices (monthly only since PR 7) are unknown.
        self::assertNull($service->tierForPriceId('price_loop_plus_yearly'));
    }

    public function testSyncFromStripeMirrorsSubscriptionOntoUser(): void
    {
        $service = $this->service();
        $user = new User();

        $service->syncFromStripe($user, Subscription::constructFrom([
            'id' => 'sub_123',
            'status' => 'active',
            'customer' => 'cus_1',
            'current_period_end' => 1_750_000_000,
            'trial_end' => 1_749_000_000,
            'items' => ['data' => [['price' => ['id' => self::LOOPP_M]]]],
        ]));

        self::assertSame(SubscriptionTier::LoopPlus, $user->getSubscriptionTier());
        self::assertSame('active', $user->getSubscriptionStatus());
        self::assertSame('sub_123', $user->getStripeSubscriptionId());
        self::assertSame(1_750_000_000, $user->getSubscriptionPeriodEnd()?->getTimestamp());
        self::assertSame(1_749_000_000, $user->getTrialEndsAt()?->getTimestamp());
    }

    public function testSyncWithUnknownPriceLeavesTierUnchanged(): void
    {
        $service = $this->service();
        $user = (new User())->setSubscriptionTier(SubscriptionTier::Loop);

        $service->syncFromStripe($user, Subscription::constructFrom([
            'id' => 'sub_x',
            'status' => 'past_due',
            'items' => ['data' => [['price' => ['id' => 'price_unknown']]]],
        ]));

        // Tier preserved, but the status still syncs.
        self::assertSame(SubscriptionTier::Loop, $user->getSubscriptionTier());
        self::assertSame('past_due', $user->getSubscriptionStatus());
    }

    /** A cancellation scheduled in the portal: cancel_at on recent API versions. */
    public function testSyncStoresAScheduledCancellation(): void
    {
        $service = $this->service();
        $user = new User();

        $service->syncFromStripe($user, Subscription::constructFrom([
            'id' => 'sub_1', 'status' => 'active', 'current_period_end' => 1_750_000_000,
            'cancel_at' => 1_750_000_000, 'cancel_at_period_end' => false,
            'items' => ['data' => [['price' => ['id' => self::LOOPP_M]]]],
        ]));
        self::assertSame(1_750_000_000, $user->getSubscriptionCancelAt()?->getTimestamp());

        // Older API versions: cancel_at_period_end means the period end.
        $user = new User();
        $service->syncFromStripe($user, Subscription::constructFrom([
            'id' => 'sub_1', 'status' => 'active', 'current_period_end' => 1_750_000_000,
            'cancel_at_period_end' => true,
            'items' => ['data' => [['price' => ['id' => self::LOOPP_M]]]],
        ]));
        self::assertSame(1_750_000_000, $user->getSubscriptionCancelAt()?->getTimestamp());
    }

    /** Renewing in the portal clears the cancellation. */
    public function testSyncClearsAResumedCancellation(): void
    {
        $service = $this->service();
        $user = (new User())->setSubscriptionCancelAt(new \DateTimeImmutable('2026-07-01'));

        $service->syncFromStripe($user, Subscription::constructFrom([
            'id' => 'sub_1', 'status' => 'active', 'current_period_end' => 1_750_000_000,
            'cancel_at' => null, 'cancel_at_period_end' => false,
            'items' => ['data' => [['price' => ['id' => self::LOOPP_M]]]],
        ]));

        self::assertNull($user->getSubscriptionCancelAt());
    }

    private function service(): SubscriptionService
    {
        return new SubscriptionService(
            new MockClock(new \DateTimeImmutable('2026-06-24 12:00:00')),
            null,
            null,
            null,
            self::LOOP_M,
            self::LOOPP_M,
        );
    }
}

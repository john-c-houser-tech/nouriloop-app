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
    private const LOOP_Y = 'price_loop_yearly';
    private const LOOPP_M = 'price_loop_plus_monthly';
    private const LOOPP_Y = 'price_loop_plus_yearly';

    public function testTierForPriceIdMapsKnownPrices(): void
    {
        $service = $this->service();

        self::assertSame(SubscriptionTier::Loop, $service->tierForPriceId(self::LOOP_M));
        self::assertSame(SubscriptionTier::Loop, $service->tierForPriceId(self::LOOP_Y));
        self::assertSame(SubscriptionTier::LoopPlus, $service->tierForPriceId(self::LOOPP_M));
        self::assertSame(SubscriptionTier::LoopPlus, $service->tierForPriceId(self::LOOPP_Y));
    }

    public function testUnknownAndEmptyPriceIdsAreNotResolved(): void
    {
        $service = $this->service();

        self::assertNull($service->tierForPriceId('price_unknown'));
        self::assertNull($service->tierForPriceId(''));
        self::assertFalse($service->isKnownPriceId('price_unknown'));
        self::assertTrue($service->isKnownPriceId(self::LOOPP_Y));
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

    private function service(): SubscriptionService
    {
        return new SubscriptionService(
            new MockClock(new \DateTimeImmutable('2026-06-24 12:00:00')),
            null,
            null,
            null,
            self::LOOP_M,
            self::LOOP_Y,
            self::LOOPP_M,
            self::LOOPP_Y,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\SubscriptionTier;
use PHPUnit\Framework\TestCase;

final class SubscriptionTierTest extends TestCase
{
    private const SHARED_PAID_FEATURES = [
        'paid_plan', 'unlimited_checkins', 'daily_checkins', 'checkin_delivery',
        'cgm_correlation', 'ai_pattern_coach', 'predictive_help',
    ];

    public function testFreeHasNoPaidFeatures(): void
    {
        foreach ([...self::SHARED_PAID_FEATURES, 'sms'] as $feature) {
            self::assertFalse(SubscriptionTier::Free->hasFeature($feature), $feature);
        }
    }

    /** Both paid plans are full-featured since PR 7 (ADR 0003)… */
    public function testBothPaidPlansHaveEveryFeature(): void
    {
        foreach ([SubscriptionTier::Loop, SubscriptionTier::LoopPlus] as $tier) {
            foreach (self::SHARED_PAID_FEATURES as $feature) {
                self::assertTrue($tier->hasFeature($feature), $tier->value . ' ' . $feature);
            }
        }
    }

    /** …except SMS, whose Twilio fees the bring-your-own-key price doesn't cover. */
    public function testSmsIsLoopPlusOnly(): void
    {
        self::assertFalse(SubscriptionTier::Loop->hasFeature('sms'));
        self::assertTrue(SubscriptionTier::LoopPlus->hasFeature('sms'));
    }

    public function testUnknownFeatureDeniedByDefault(): void
    {
        self::assertFalse(SubscriptionTier::LoopPlus->hasFeature('teleportation'));
        self::assertFalse(SubscriptionTier::Loop->hasFeature(''));

        // The two pre-tiering keys were retired, not kept as always-true traps —
        // goal definition is free for everyone, and check-in gating moved to the
        // unlimited/daily/delivery keys above. Deny-by-default covers them now.
        self::assertFalse(SubscriptionTier::LoopPlus->hasFeature('goals'));
        self::assertFalse(SubscriptionTier::LoopPlus->hasFeature('scheduled_checkins'));

        // 'favorites' was retired the same way (2026-07-22): the key was never
        // enforced anywhere, favorites are free on every tier (the recipe photo
        // import depends on that), and the paid lever is the AnalysisAllowance.
        self::assertFalse(SubscriptionTier::LoopPlus->hasFeature('favorites'));

        // Retired by the tier rework: analyses are AnalysisAllowance's business,
        // and SMS is one 'sms' key for both the digest and check-ins.
        self::assertFalse(SubscriptionTier::LoopPlus->hasFeature('unlimited_photos'));
        self::assertFalse(SubscriptionTier::LoopPlus->hasFeature('sms_digest'));
    }

    public function testLabels(): void
    {
        self::assertSame('Free', SubscriptionTier::Free->label());
        self::assertSame('Loop', SubscriptionTier::Loop->label());
        self::assertSame('Loop+', SubscriptionTier::LoopPlus->label());
    }

    public function testRankOrdersTiers(): void
    {
        self::assertGreaterThan(SubscriptionTier::Free->rank(), SubscriptionTier::Loop->rank());
        self::assertGreaterThan(SubscriptionTier::Loop->rank(), SubscriptionTier::LoopPlus->rank());
    }

    public function testDisplayPrices(): void
    {
        self::assertSame(0, SubscriptionTier::Free->monthlyPriceCents());
        self::assertSame(200, SubscriptionTier::Loop->monthlyPriceCents());
        self::assertSame(1350, SubscriptionTier::LoopPlus->monthlyPriceCents());

        self::assertSame('0', SubscriptionTier::Free->monthlyPriceUsd());
        self::assertSame('2', SubscriptionTier::Loop->monthlyPriceUsd());
        self::assertSame('13.50', SubscriptionTier::LoopPlus->monthlyPriceUsd());
    }
}

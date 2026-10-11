<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\SubscriptionTier;
use PHPUnit\Framework\TestCase;

final class SubscriptionTierTest extends TestCase
{
    public function testFreeHasNoPaidFeatures(): void
    {
        $free = SubscriptionTier::Free;

        self::assertFalse($free->hasFeature('unlimited_photos'));
        self::assertFalse($free->hasFeature('unlimited_checkins'));
        self::assertFalse($free->hasFeature('sms_digest'));
        self::assertFalse($free->hasFeature('daily_checkins'));
        self::assertFalse($free->hasFeature('checkin_delivery'));
        self::assertFalse($free->hasFeature('cgm_correlation'));
        self::assertFalse($free->hasFeature('ai_pattern_coach'));
    }

    public function testLoopHasCoreFeaturesButNotPlusFeatures(): void
    {
        $loop = SubscriptionTier::Loop;

        self::assertTrue($loop->hasFeature('unlimited_photos'));
        self::assertTrue($loop->hasFeature('unlimited_checkins'));
        self::assertTrue($loop->hasFeature('sms_digest'));

        // Loop+ exclusives stay locked on Loop.
        self::assertFalse($loop->hasFeature('daily_checkins'));
        self::assertFalse($loop->hasFeature('checkin_delivery'));
        self::assertFalse($loop->hasFeature('cgm_correlation'));
        self::assertFalse($loop->hasFeature('ai_pattern_coach'));
    }

    public function testLoopPlusHasEveryFeature(): void
    {
        $plus = SubscriptionTier::LoopPlus;

        self::assertTrue($plus->hasFeature('unlimited_photos'));
        self::assertTrue($plus->hasFeature('unlimited_checkins'));
        self::assertTrue($plus->hasFeature('sms_digest'));
        self::assertTrue($plus->hasFeature('daily_checkins'));
        self::assertTrue($plus->hasFeature('checkin_delivery'));
        self::assertTrue($plus->hasFeature('cgm_correlation'));
        self::assertTrue($plus->hasFeature('ai_pattern_coach'));
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
        self::assertSame(0, SubscriptionTier::Free->monthlyPriceUsd());
        self::assertSame(0, SubscriptionTier::Free->yearlyPriceUsd());
        self::assertSame(5, SubscriptionTier::Loop->monthlyPriceUsd());
        self::assertSame(48, SubscriptionTier::Loop->yearlyPriceUsd());
        self::assertSame(9, SubscriptionTier::LoopPlus->monthlyPriceUsd());
        self::assertSame(79, SubscriptionTier::LoopPlus->yearlyPriceUsd());
    }
}

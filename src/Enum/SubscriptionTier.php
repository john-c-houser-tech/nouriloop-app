<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A user's subscription tier. Drives feature gating across the app — always check
 * through SubscriptionService (which applies the grace-period / lazy-downgrade
 * rules) rather than reading the stored tier directly.
 *
 * Known feature keys (see hasFeature): unlimited_photos,
 * unlimited_checkins, sms_digest, daily_checkins, checkin_delivery,
 * cgm_correlation, ai_pattern_coach, predictive_help.
 *
 * Goal *definition* is deliberately ungated (free for everyone) — the paid
 * ladder is check-in cadence/count and push delivery. The Free tier gets a
 * lifetime allowance of evaluated check-ins instead (see CheckinAllowance).
 *
 * Favorites are likewise ungated: the 'favorites' key was retired (2026-07-22,
 * like the pre-tiering goals/scheduled_checkins keys) — it was never enforced
 * anywhere, the recipe photo import depends on Free users saving favorites,
 * and favorite reuse *saves* analysis spend. What Free actually pays for is
 * the monthly AnalysisAllowance that creates favorites in the first place.
 */
enum SubscriptionTier: string
{
    case Free     = 'free';
    case Loop     = 'loop';
    case LoopPlus = 'loop_plus';

    public function label(): string
    {
        return match ($this) {
            self::Free     => 'Free',
            self::Loop     => 'Loop',
            self::LoopPlus => 'Loop+',
        };
    }

    /**
     * Ordinal rank for comparing tiers (Free < Loop < Loop+), so the higher of two
     * entitlements (e.g. a comp vs. a paid subscription) can be picked.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Free     => 0,
            self::Loop     => 1,
            self::LoopPlus => 2,
        };
    }

    /**
     * Inverse of rank() — null when off the ladder (below Free / above Loop+).
     * Powers the admin one-step Upgrade/Downgrade actions.
     */
    public static function fromRank(int $rank): ?self
    {
        foreach (self::cases() as $tier) {
            if ($tier->rank() === $rank) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * Display price in whole USD. Single source of truth for the pricing page and the
     * /about JSON-LD offers (so they can't drift); also stated in the ToS prose. Free
     * is 0. (Stripe is the billing authority — these are presentation values only.)
     */
    public function monthlyPriceUsd(): int
    {
        return match ($this) {
            self::Free     => 0,
            self::Loop     => 5,
            self::LoopPlus => 9,
        };
    }

    public function yearlyPriceUsd(): int
    {
        return match ($this) {
            self::Free     => 0,
            self::Loop     => 48,
            self::LoopPlus => 79,
        };
    }

    /**
     * Whether this tier includes the given feature. Unknown keys return false
     * (deny by default), so a typo never silently grants access.
     */
    public function hasFeature(string $feature): bool
    {
        return match ($feature) {
            'unlimited_photos'   => $this !== self::Free,
            // Unlimited weekly goal check-ins (Free gets a lifetime allowance instead)
            // and SMS delivery of the daily digest — Loop and up.
            'unlimited_checkins' => $this !== self::Free,
            'sms_digest'         => $this !== self::Free,
            // Daily check-in cadence and email/SMS push of check-ins — Loop+ only.
            'daily_checkins'     => $this === self::LoopPlus,
            'checkin_delivery'   => $this === self::LoopPlus,
            'cgm_correlation'    => $this === self::LoopPlus,
            'ai_pattern_coach'   => $this === self::LoopPlus,
            'predictive_help'    => $this === self::LoopPlus,
            default              => false,
        };
    }
}

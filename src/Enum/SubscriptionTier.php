<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A user's subscription tier. Drives feature gating across the app — always check
 * through SubscriptionService (which applies the grace-period / lazy-downgrade
 * rules) rather than reading the stored tier directly.
 *
 * Known feature keys (see hasFeature): paid_plan, sms,
 * unlimited_checkins, daily_checkins, checkin_delivery,
 * cgm_correlation, ai_pattern_coach, predictive_help.
 *
 * Since the tier rework (App Development Plan PR 7, 2026-10-12; ADR 0003) both
 * paid plans are full-featured: Loop is the bring-your-own-key plan and Loop+
 * the AI-included plan, and the only feature between them is SMS, whose Twilio
 * fees a user's AI key doesn't cover. How many analyses a plan includes is
 * AnalysisAllowance's business, not a feature key.
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
     * Display price per month in US cents (Loop+ is $13.50, not a whole dollar).
     * Single source of truth for the pricing page, the landing pages and the /about
     * JSON-LD offers (so they can't drift); also stated in the ToS prose. Free is 0.
     * Stripe is the billing authority — these are presentation values only. Billing
     * is monthly only since PR 7; the yearly prices were retired.
     */
    public function monthlyPriceCents(): int
    {
        return match ($this) {
            self::Free     => 0,
            self::Loop     => 200,
            self::LoopPlus => 1350,
        };
    }

    /**
     * The monthly price as a plain decimal string ("2", "13.50") — the machine form
     * the JSON-LD offers carry. Display formatting is SubscriptionExtension's.
     */
    public function monthlyPriceUsd(): string
    {
        $cents = $this->monthlyPriceCents();

        return $cents % 100 === 0
            ? (string) intdiv($cents, 100)
            : sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * Whether this tier includes the given feature. Unknown keys return false
     * (deny by default), so a typo never silently grants access.
     */
    public function hasFeature(string $feature): bool
    {
        return match ($feature) {
            // Any paid plan — drives the navbar Upgrade nudge, shown to Free only.
            'paid_plan'          => $this !== self::Free,
            // Digest and check-in texts — Loop+ only: Twilio's fees are a cost the
            // bring-your-own-key plan's price doesn't cover.
            'sms'                => $this === self::LoopPlus,
            // Everything else is on both paid plans; Free keeps its lifetime
            // check-in allowance (CheckinAllowance) and weekly cadence.
            'unlimited_checkins' => $this !== self::Free,
            'daily_checkins'     => $this !== self::Free,
            'checkin_delivery'   => $this !== self::Free,
            'cgm_correlation'    => $this !== self::Free,
            'ai_pattern_coach'   => $this !== self::Free,
            'predictive_help'    => $this !== self::Free,
            default              => false,
        };
    }
}

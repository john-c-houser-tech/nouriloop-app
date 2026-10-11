<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use App\Service\SubscriptionService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `has_feature('cgm_correlation')` — whether the currently logged-in user's effective
 * subscription tier includes the given feature. Returns false when nobody is logged in,
 * so templates can gate UI without a separate null check. Entitlement always flows
 * through {@see SubscriptionService} (grace period + comp + lazy downgrade).
 */
class SubscriptionExtension extends AbstractExtension
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('has_feature', $this->hasFeature(...)),
            new TwigFunction('subscription_price', $this->subscriptionPrice(...)),
        ];
    }

    /**
     * Display price in whole USD for a tier ('free'|'loop'|'loop_plus') and period
     * ('monthly'|'annual'). One source of truth (SubscriptionTier) for the pricing page
     * and the /about JSON-LD offers, so they can't drift.
     */
    public function subscriptionPrice(string $tier, string $period = 'monthly'): int
    {
        $case = SubscriptionTier::tryFrom($tier) ?? SubscriptionTier::Free;

        return $period === 'annual' ? $case->yearlyPriceUsd() : $case->monthlyPriceUsd();
    }

    public function hasFeature(string $feature): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->subscriptions->hasFeature($user, $feature);
    }
}

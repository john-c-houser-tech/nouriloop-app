<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use App\Service\SubscriptionService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;
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
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('has_feature', $this->hasFeature(...)),
            new TwigFunction('subscription_price', $this->subscriptionPrice(...)),
            new TwigFunction('usd_amount', $this->usdAmount(...)),
        ];
    }

    /**
     * Display price per month for a tier ('free'|'loop'|'loop_plus'), formatted for the
     * current locale without a currency sign ("2", "13.50", "13,50"): templates and
     * catalogs put the "$" around it. One source of truth (SubscriptionTier) for the
     * pricing page, the landing pages and the /about JSON-LD offers, so they can't drift.
     * A string, not a number: ICU would print 13.5 for a float. Billing is monthly only.
     */
    public function subscriptionPrice(string $tier): string
    {
        return $this->usdAmount((SubscriptionTier::tryFrom($tier) ?? SubscriptionTier::Free)->monthlyPriceCents());
    }

    /**
     * Any USD amount in cents, formatted like subscription_price() (no currency sign) —
     * e.g. the allowance top-up's AnalysisAllowance::TOP_UP_PRICE_CENTS.
     */
    public function usdAmount(int $cents): string
    {
        $format = new \NumberFormatter($this->translator->getLocale(), \NumberFormatter::DECIMAL);
        $format->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $cents % 100 === 0 ? 0 : 2);
        $format->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return (string) $format->format($cents / 100);
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

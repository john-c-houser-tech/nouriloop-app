<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Stripe\Subscription;

/**
 * Single entry point for subscription entitlement decisions, plus the Stripe-facing
 * checkout / portal / webhook-sync methods.
 *
 * The entitlement half (grace period, comp grants, effective tier) is deliberately
 * pure and SDK-free — always gate features through here rather than reading the
 * stored tier. The Stripe dependencies are optional constructor args (filled by DI in
 * the web app, left null in the pure entitlement unit tests) so that gating logic
 * stays trivially unit-testable without a Stripe client.
 */
class SubscriptionService
{
    /** Stripe statuses that grant full access right now. */
    public const ACTIVE_STATUSES = ['active', 'trialing'];

    /** Statuses that retain access only until the paid period ends (grace period). */
    public const GRACE_STATUSES = ['canceled', 'past_due'];

    /**
     * The complimentary offer for a beta-tester invitation: Loop+ for 45 days.
     *
     * ⚠️ Shortened from one year on 2026-07-26, deliberately and for one reason:
     * a year-long comp makes a beta tester **structurally incapable** of
     * converting to paid, so the "zero paying users" figure carried no
     * information at all. With ~12 registrations and exactly one unsolicited
     * active user, the expiry of these comps is the only read available on
     * whether anyone will pay (Marketing Plan §7.7). Tell testers the duration
     * up front — the point is a genuine signal, not a surprise.
     *
     * Existing comps are untouched: this is read only when a NEW beta code is
     * redeemed. The one-off `app:grandfather-beta-testers` command grants
     * PERMANENT comps to the pre-launch cohort and is deliberately separate —
     * they were promised that, and it must not start expiring them.
     */
    public const BETA_COMP_TIER = SubscriptionTier::LoopPlus;
    public const BETA_COMP_DURATION = '+45 days';

    /**
     * The same duration as an operator reads it. Every surface that PROMISES
     * the offer — the mint checkbox, the outstanding-code badge, the mint
     * flash — renders this, so shortening the grant can't leave an admin
     * telling a tester "1 year" for a code that now grants 45 days (Copilot
     * catch on PR #184). Not cosmetic: the whole value of the shortened comp
     * is that the tester is told the term up front, and the admin is the one
     * doing the telling.
     */
    public const BETA_COMP_LABEL = '45 days';

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly ?StripeClient $stripe = null,
        private readonly ?EntityManagerInterface $em = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $loopMonthlyPriceId = '',
        private readonly string $loopYearlyPriceId = '',
        private readonly string $loopPlusMonthlyPriceId = '',
        private readonly string $loopPlusYearlyPriceId = '',
    ) {
    }

    /**
     * Whether the user currently has a paid subscription in good standing — either
     * actively paying/trialing, or within the grace period after a cancellation or
     * a failed payment (access through the end of the period they paid for).
     */
    public function isSubscriptionActive(User $user): bool
    {
        $status = $user->getSubscriptionStatus();

        if (in_array($status, self::ACTIVE_STATUSES, true)) {
            return true;
        }

        if (in_array($status, self::GRACE_STATUSES, true)) {
            $periodEnd = $user->getSubscriptionPeriodEnd();

            return $periodEnd !== null && $periodEnd > $this->clock->now();
        }

        return false;
    }

    /**
     * The tier the user is *currently entitled to*: the **highest** of their active
     * entitlements — a live complimentary grant (comp, used to grandfather beta
     * testers independent of Stripe) and an active (or in-grace) Stripe subscription.
     * Taking the max means a comp never *downgrades* someone who pays for a higher
     * tier, and a higher comp still beats a lower paid sub. With neither active it is
     * Free. No cron is needed to expire either — the check at access time is enough.
     */
    public function effectiveTier(User $user): SubscriptionTier
    {
        $tier = SubscriptionTier::Free;

        if ($this->hasActiveComp($user)) {
            $tier = $this->higher($tier, $user->getCompTier());
        }

        if ($this->isSubscriptionActive($user)) {
            $tier = $this->higher($tier, $user->getSubscriptionTier());
        }

        return $tier;
    }

    private function higher(SubscriptionTier $a, SubscriptionTier $b): SubscriptionTier
    {
        return $b->rank() > $a->rank() ? $b : $a;
    }

    /**
     * The tier backed by Stripe alone (active or in-grace), ignoring any comp —
     * the floor the admin Upgrade/Downgrade ladder can't go below, since pushing a
     * paying subscriber lower would mean touching their actual billing.
     */
    public function paidTier(User $user): SubscriptionTier
    {
        return $this->isSubscriptionActive($user) ? $user->getSubscriptionTier() : SubscriptionTier::Free;
    }

    /**
     * Whether the user has a complimentary grant in effect right now — a comp tier
     * is set and either permanent (no expiry) or not yet expired.
     */
    public function hasActiveComp(User $user): bool
    {
        if ($user->getCompTier() === null) {
            return false;
        }

        $expiresAt = $user->getCompExpiresAt();

        return $expiresAt === null || $expiresAt > $this->clock->now();
    }

    /**
     * Grant a complimentary entitlement. $expiresAt null = permanent (the existing
     * beta cohort); a date = time-limited. Does not flush — the caller owns the unit
     * of work.
     */
    public function grantComp(User $user, SubscriptionTier $tier, ?\DateTimeImmutable $expiresAt): void
    {
        $user->setCompTier($tier);
        $user->setCompExpiresAt($expiresAt);
    }

    /**
     * Remove any complimentary entitlement. Like grantComp, does not flush —
     * the caller owns the unit of work.
     */
    public function revokeComp(User $user): void
    {
        $user->setCompTier(null);
        $user->setCompExpiresAt(null);
    }

    /**
     * Grant the standard beta-tester comp — Loop+ for BETA_COMP_DURATION from
     * now. Used by the beta-tester invitation-code redemption path.
     */
    public function grantBetaTesterComp(User $user): void
    {
        $this->grantComp(
            $user,
            self::BETA_COMP_TIER,
            $this->clock->now()->modify(self::BETA_COMP_DURATION),
        );
    }

    /**
     * Whether the user's effective tier includes the given feature. See
     * SubscriptionTier::hasFeature for the known feature keys.
     */
    public function hasFeature(User $user, string $feature): bool
    {
        return $this->effectiveTier($user)->hasFeature($feature);
    }

    /**
     * Best-effort: mirror a changed account email onto the Stripe customer so
     * receipts/dunning don't keep going to a dead address. Never throws — a
     * Stripe hiccup must not break the email-change flow; the local change is
     * the source of truth. A failed sync is only logged (no retry path — the
     * user can also fix the billing email themselves in the Stripe portal).
     */
    public function syncCustomerEmail(User $user): void
    {
        $customerId = $user->getStripeCustomerId();
        if ($this->stripe === null || $customerId === null) {
            return;
        }

        try {
            $this->stripe->customers->update($customerId, ['email' => (string) $user->getEmail()]);
        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to sync changed email to Stripe customer {customer}: {error}', [
                'customer' => $customerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Stripe-facing methods ──────────────────────────────────────────────────

    /**
     * Start a Stripe Checkout session for the given price and return its hosted URL.
     * Lazily creates (and persists) a Stripe customer for the user on first checkout
     * so the webhook can later resolve the user by customer id. New subscribers (no
     * prior subscription) get a 7-day trial.
     */
    public function createCheckoutSession(
        User $user,
        string $priceId,
        string $successUrl,
        string $cancelUrl,
    ): string {
        \assert($this->stripe !== null && $this->em !== null);

        $customerId = $user->getStripeCustomerId();
        if ($customerId === null) {
            $customer = $this->stripe->customers->create(['email' => $user->getEmail()]);
            $customerId = $customer->id;
            $user->setStripeCustomerId($customerId);
            $this->em->flush();
        }

        $params = [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        // 7-day free trial only for users who have never had a subscription.
        if ($user->getStripeSubscriptionId() === null) {
            $params['subscription_data'] = ['trial_period_days' => 7];
        }

        $session = $this->stripe->checkout->sessions->create($params);

        return (string) $session->url;
    }

    /**
     * Open a Stripe Billing Portal session (manage payment method, cancel, etc.) and
     * return its hosted URL. The user must already be a Stripe customer.
     */
    public function createPortalSession(User $user, string $returnUrl): string
    {
        \assert($this->stripe !== null);

        $customerId = $user->getStripeCustomerId();
        if ($customerId === null) {
            throw new \LogicException('Cannot open the billing portal for a user without a Stripe customer.');
        }

        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return (string) $session->url;
    }

    /**
     * Mirror a Stripe Subscription onto the user's columns (tier, status, period end,
     * subscription id, trial end). Does NOT flush — the caller (webhook / success
     * handler) owns the unit of work. An unknown price leaves the tier unchanged.
     */
    public function syncFromStripe(User $user, Subscription $sub): void
    {
        // isset() (StripeObject::__isset) avoids the "undefined property" notices the
        // SDK emits for keys a given API version / object may omit.
        $priceId = isset($sub->items->data[0]->price->id) ? $sub->items->data[0]->price->id : null;
        $tier = is_string($priceId) ? $this->tierForPriceId($priceId) : null;

        if ($tier !== null) {
            $user->setSubscriptionTier($tier);
        } else {
            $this->logger?->warning(
                'Stripe subscription {sub} has an unknown price {price}; leaving tier unchanged.',
                ['sub' => $sub->id, 'price' => $priceId],
            );
        }

        $user->setSubscriptionStatus((string) $sub->status);
        $user->setStripeSubscriptionId((string) $sub->id);

        // current_period_end moved onto subscription items in recent API versions —
        // fall back to the item so this works regardless of the pinned version.
        $periodEnd = match (true) {
            isset($sub->current_period_end)                 => $sub->current_period_end,
            isset($sub->items->data[0]->current_period_end) => $sub->items->data[0]->current_period_end,
            default                                         => null,
        };
        if ($periodEnd !== null) {
            $user->setSubscriptionPeriodEnd((new \DateTimeImmutable())->setTimestamp((int) $periodEnd));
        }

        if (isset($sub->trial_end)) {
            $user->setTrialEndsAt((new \DateTimeImmutable())->setTimestamp((int) $sub->trial_end));
        }
    }

    /**
     * Best-effort fast-path sync right after Checkout returns (the webhook remains the
     * authoritative path). Resolves the subscription behind a Checkout session and
     * syncs + flushes it; swallows + logs any failure so the success page never errors.
     *
     * The session id arrives in a query string, so it's untrusted: the session must
     * belong to this user's Stripe customer, or nothing is synced. Otherwise anyone
     * holding another customer's session id could stamp that subscription onto their
     * own row (and uq_user_stripe_subscription would make the mis-attach stick,
     * breaking the real owner's webhook sync).
     */
    public function syncFromCheckoutSession(User $user, string $sessionId): void
    {
        if ($this->stripe === null || $this->em === null) {
            return;
        }

        try {
            $session = $this->stripe->checkout->sessions->retrieve($sessionId);
            $customer = $session->customer;
            $customerId = is_string($customer) ? $customer : ($customer->id ?? null);
            if ($customerId === null || $customerId !== $user->getStripeCustomerId()) {
                $this->logger?->warning(
                    'Checkout session {session} belongs to customer {customer}, not user {user}; not syncing.',
                    ['session' => $sessionId, 'customer' => $customerId, 'user' => $user->getId()],
                );

                return;
            }

            $subscription = $session->subscription;
            $subId = is_string($subscription) ? $subscription : ($subscription->id ?? null);
            if ($subId === null) {
                return;
            }

            $sub = $this->stripe->subscriptions->retrieve($subId);
            $this->syncFromStripe($user, $sub);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger?->warning('Checkout-session sync failed: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Map a Stripe Price id to the tier it grants, or null if it isn't one of the four
     * known plan prices.
     */
    public function tierForPriceId(string $priceId): ?SubscriptionTier
    {
        if ($priceId === '') {
            return null;
        }

        return match (true) {
            in_array($priceId, [$this->loopMonthlyPriceId, $this->loopYearlyPriceId], true)
                => SubscriptionTier::Loop,
            in_array($priceId, [$this->loopPlusMonthlyPriceId, $this->loopPlusYearlyPriceId], true)
                => SubscriptionTier::LoopPlus,
            default => null,
        };
    }

    /** Whether the given Stripe Price id is one of the four known plan prices. */
    public function isKnownPriceId(string $priceId): bool
    {
        return $this->tierForPriceId($priceId) !== null;
    }

    /**
     * Cancel the user's Stripe subscription immediately, called when they request
     * account deletion (self-service or admin-triggered) so they stop being billed
     * right away rather than continuing through the grace period. Best-effort — a
     * failure is logged, never thrown, since it must never block the deletion itself.
     * Does not touch the DB: the existing webhook (`customer.subscription.deleted`)
     * syncs the status once Stripe confirms the cancellation.
     */
    public function cancelSubscriptionForDeletion(User $user): void
    {
        if ($this->stripe === null) {
            return;
        }

        $subId = $user->getStripeSubscriptionId();
        // Already canceled (e.g. the grace-period request already did this, or the
        // user canceled independently via the portal) — skip the redundant/erroring
        // Stripe call when the purge cron later hard-deletes the same account.
        if ($subId === null || $user->getSubscriptionStatus() === 'canceled') {
            return;
        }

        try {
            $this->stripe->subscriptions->cancel($subId);
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'Failed to cancel Stripe subscription {sub} on account deletion: {error}',
                ['sub' => $subId, 'error' => $e->getMessage()],
            );
        }
    }
}

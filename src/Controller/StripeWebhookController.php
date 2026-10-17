<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AllowanceTopUpService;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Invoice;
use Stripe\StripeClient;
use Stripe\Subscription;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stripe webhook receiver. The single source of truth for subscription state — every
 * relevant lifecycle event is verified by signature, then mirrored onto the user's
 * columns via {@see SubscriptionService::syncFromStripe}.
 *
 * Machine-to-machine: PUBLIC_ACCESS in security.yaml (authenticated by Stripe's
 * signature, not a session) and not a Symfony Form, so no CSRF applies. Per-event
 * handlers swallow + log app-level errors and still return 200, so Stripe only retries
 * on genuine infrastructure failures (a non-200) rather than on our own bugs.
 */
class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly AllowanceTopUpService $topUps,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly StripeClient $stripe,
        private readonly LoggerInterface $logger,
        private readonly string $stripeWebhookSecret,
    ) {
    }

    #[Route(
        '/webhook/stripe',
        name: 'app_stripe_webhook',
        methods: ['POST'],
        condition: "service('hosting').isHosted()",
    )]
    public function handle(Request $request): Response
    {
        $payload   = $request->getContent();
        $sigHeader = $request->headers->get('stripe-signature', '');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $this->stripeWebhookSecret);
        } catch (SignatureVerificationException) {
            return new Response('Bad signature', Response::HTTP_BAD_REQUEST);
        } catch (\UnexpectedValueException) {
            return new Response('Invalid payload', Response::HTTP_BAD_REQUEST);
        }

        try {
            $object = $event->data->object;

            // A paid top-up has no other way to reach the user if crediting fails here
            // (the success page's fast path is best-effort), so unlike the subscription
            // events below a failure is NOT acknowledged: a 500 makes Stripe retry, and
            // credit() is idempotent. Copilot catch on #248.
            $topUpEvents = ['checkout.session.completed', 'checkout.session.async_payment_succeeded'];
            if (\in_array($event->type, $topUpEvents, true) && $object instanceof Session) {
                return $this->creditTopUp($object);
            }

            switch ($event->type) {
                case 'customer.subscription.created':
                case 'customer.subscription.updated':
                    if ($object instanceof Subscription) {
                        $this->syncSubscription($object);
                    }
                    break;

                case 'customer.subscription.deleted':
                    if ($object instanceof Subscription) {
                        $this->cancelSubscription($object);
                    }
                    break;

                case 'invoice.payment_succeeded':
                    if ($object instanceof Invoice) {
                        $this->resyncFromInvoice($object);
                    }
                    break;

                case 'invoice.payment_failed':
                    if ($object instanceof Invoice) {
                        $this->markPastDue($object);
                    }
                    break;

                case 'customer.subscription.trial_will_end':
                    // Placeholder for a future heads-up email before the trial converts.
                    $this->logger->notice('Stripe trial ending soon for subscription {id}.', [
                        'id' => $object instanceof Subscription ? $object->id : null,
                    ]);
                    break;

                default:
                    // Unhandled event types are acknowledged so Stripe stops retrying.
                    break;
            }
        } catch (\Throwable $e) {
            // App-level failure — log and still 200 so Stripe doesn't hammer us with
            // retries for a bug it can't fix.
            $this->logger->error('Stripe webhook handler failed for {type}: {error}', [
                'type'  => $event->type,
                'error' => $e->getMessage(),
            ]);
        }

        return new Response('', Response::HTTP_OK);
    }

    /**
     * A Loop+ allowance top-up (a one-off payment, PR 7b). Subscription checkouts also
     * complete with this event; credit() ignores anything that isn't a paid top-up.
     */
    private function creditTopUp(Session $session): Response
    {
        try {
            $this->topUps->credit($session);
        } catch (\Throwable $e) {
            $this->logger->error('Stripe top-up credit failed for {session}; asking Stripe to retry: {error}', [
                'session' => $session->id,
                'error'   => $e->getMessage(),
            ]);

            return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response('', Response::HTTP_OK);
    }

    private function syncSubscription(Subscription $sub): void
    {
        $user = $this->userForCustomer(is_string($sub->customer) ? $sub->customer : ($sub->customer->id ?? null));
        if ($user === null) {
            return;
        }

        // A late event for a subscription the user has since replaced must not overwrite
        // the current one. A new subscription after the stored one ended still syncs
        // (Copilot catch on #250).
        if ($this->isReplaced($user, (string) $sub->id)) {
            return;
        }

        $this->subscriptions->syncFromStripe($user, $sub);
        $this->em->flush();
    }

    private function cancelSubscription(Subscription $sub): void
    {
        $user = $this->userForCustomer(is_string($sub->customer) ? $sub->customer : ($sub->customer->id ?? null));
        if ($user === null) {
            return;
        }

        // A late event for a subscription the user has since replaced must not cancel the
        // current one (PR 8, 2026-10-13).
        if ($user->getStripeSubscriptionId() !== null && $user->getStripeSubscriptionId() !== $sub->id) {
            $this->logIgnored($user, (string) $sub->id);

            return;
        }

        // Keep subscriptionPeriodEnd as-is so the grace-period logic keeps access until
        // the paid period actually ends.
        $user->setSubscriptionStatus('canceled');
        $this->em->flush();
    }

    private function resyncFromInvoice(Invoice $invoice): void
    {
        $subId = $this->subscriptionIdFromInvoice($invoice);
        if ($subId === null) {
            return;
        }

        $sub = $this->stripe->subscriptions->retrieve($subId);
        $this->syncSubscription($sub);
    }

    private function markPastDue(Invoice $invoice): void
    {
        $customerId = is_string($invoice->customer) ? $invoice->customer : ($invoice->customer->id ?? null);
        $user = $this->userForCustomer($customerId);
        if ($user === null) {
            return;
        }

        $subId = $this->subscriptionIdFromInvoice($invoice);
        if ($subId !== null && $this->isReplaced($user, $subId)) {
            return;
        }

        $user->setSubscriptionStatus('past_due');
        $this->em->flush();
    }

    /**
     * Whether an event about $subscriptionId concerns a subscription the user has since
     * replaced: they have a different one that is still live. Logs when it does.
     */
    private function isReplaced(User $user, string $subscriptionId): bool
    {
        $replaced = $user->getStripeSubscriptionId() !== null
            && $user->getStripeSubscriptionId() !== $subscriptionId
            && $this->subscriptions->hasLiveSubscription($user);
        if ($replaced) {
            $this->logIgnored($user, $subscriptionId);
        }

        return $replaced;
    }

    private function logIgnored(User $user, string $subscriptionId): void
    {
        $this->logger->info('Stripe event for {sub}, not the current subscription of user {user}; ignored.', [
            'sub'  => $subscriptionId,
            'user' => $user->getId(),
        ]);
    }

    private function userForCustomer(?string $customerId): ?User
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        $user = $this->users->findOneBy(['stripeCustomerId' => $customerId]);
        if ($user === null) {
            $this->logger->warning('Stripe webhook: no user for customer {id}.', ['id' => $customerId]);
        }

        return $user;
    }

    /**
     * The subscription id behind an invoice, tolerating both the legacy top-level
     * `subscription` field and the newer `parent.subscription_details.subscription`.
     */
    private function subscriptionIdFromInvoice(Invoice $invoice): ?string
    {
        $sub = $invoice->subscription ?? ($invoice->parent->subscription_details->subscription ?? null);

        return is_string($sub) ? $sub : ($sub->id ?? null);
    }
}

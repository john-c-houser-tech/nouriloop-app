<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly StripeClient $stripe,
        private readonly LoggerInterface $logger,
        private readonly string $stripeWebhookSecret,
    ) {
    }

    #[Route('/webhook/stripe', name: 'app_stripe_webhook', methods: ['POST'])]
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

    private function syncSubscription(Subscription $sub): void
    {
        $user = $this->userForCustomer(is_string($sub->customer) ? $sub->customer : ($sub->customer->id ?? null));
        if ($user === null) {
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

        $user->setSubscriptionStatus('past_due');
        $this->em->flush();
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

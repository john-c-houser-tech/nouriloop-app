<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AllowanceTopUp;
use App\Entity\User;
use App\Enum\TopUpCredit;
use App\Repository\AllowanceTopUpRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

/**
 * The Loop+ allowance top-up (App Development Plan PR 7b, decision Q3): a one-off
 * Stripe Checkout payment that adds {@see AnalysisAllowance::TOP_UP_ANALYSES} AI
 * analyses for the user's current month.
 *
 * Crediting is idempotent on the Checkout session id (unique column): the webhook
 * (`checkout.session.completed`, authoritative) and the success page's fast path
 * may both arrive, and only the first writes a row. The month is fixed in the
 * session's metadata when checkout starts, so a payment that completes after
 * midnight on the 1st still tops up the month the user was looking at.
 */
final class AllowanceTopUpService
{
    /** Marks our top-up sessions apart from subscription checkouts. */
    public const PURPOSE = 'allowance_top_up';

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly EntityManagerInterface $em,
        private readonly AllowanceTopUpRepository $topUps,
        private readonly UserRepository $users,
        private readonly SubscriptionService $subscriptions,
        private readonly AnalysisAllowance $allowance,
        private readonly UserTime $userTime,
        private readonly LoggerInterface $logger,
        private readonly string $topUpPriceId,
    ) {
    }

    /** Start a one-off Checkout for a top-up and return its hosted URL. */
    public function createCheckoutSession(User $user, string $successUrl, string $cancelUrl): string
    {
        if (!$this->allowance->canTopUp($user)) {
            throw new \LogicException('Only Loop+ on the included allowance can buy a top-up.');
        }

        $metadata = [
            'purpose' => self::PURPOSE,
            'user_id' => (string) $user->getId(),
            'month'   => $this->userTime->now($user)->format('Y-m'),
        ];
        $session = $this->stripe->checkout->sessions->create([
            'mode'                => 'payment',
            'customer'            => $this->subscriptions->ensureStripeCustomer($user),
            'line_items'          => [['price' => $this->topUpPriceId, 'quantity' => 1]],
            'metadata'            => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'success_url'         => $successUrl,
            'cancel_url'          => $cancelUrl,
        ]);

        return (string) $session->url;
    }

    /**
     * Credit a completed top-up session: Credited when this call wrote the row,
     * AlreadyCredited for a valid paid top-up that has its row, NotCredited for anything
     * else (not a top-up, not paid yet, an unknown customer, or a different user than
     * $expectedUser). A database failure throws, so the webhook can ask Stripe to retry.
     */
    public function credit(Session $session, ?User $expectedUser = null): TopUpCredit
    {
        // ?? (StripeObject::__isset) for keys a given event or API version may omit.
        $metadata = ($session->metadata ?? null)?->toArray() ?? [];
        if (($metadata['purpose'] ?? null) !== self::PURPOSE) {
            return TopUpCredit::NotCredited;
        }
        if (($session->payment_status ?? null) !== 'paid') {
            return TopUpCredit::NotCredited;
        }

        $customer = $session->customer ?? null;
        $customerId = is_string($customer) ? $customer : ($customer->id ?? null);
        $user = $customerId === null ? null : $this->users->findOneBy(['stripeCustomerId' => $customerId]);
        if ($user === null || ($expectedUser !== null && $user->getId() !== $expectedUser->getId())) {
            $this->logger->warning('Top-up session {session} does not match a user; not crediting.', [
                'session' => $session->id,
            ]);

            return TopUpCredit::NotCredited;
        }

        $month = (string) ($metadata['month'] ?? '');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $month = $this->userTime->now($user)->format('Y-m');
        }

        if ($this->topUps->isCredited((string) $session->id)) {
            return TopUpCredit::AlreadyCredited;
        }

        $this->em->persist(new AllowanceTopUp(
            $user,
            $month,
            AnalysisAllowance::TOP_UP_ANALYSES,
            (string) $session->id,
            new \DateTimeImmutable(),
        ));

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // The webhook and the success page raced; the other one wrote it.
            return TopUpCredit::AlreadyCredited;
        }

        return TopUpCredit::Credited;
    }

    /**
     * The success page's fast path: the session id arrives in a query string, so it is
     * untrusted — it must belong to $user. Never throws; the webhook remains
     * authoritative if Stripe can't be reached here.
     */
    public function creditFromSessionId(User $user, string $sessionId): TopUpCredit
    {
        try {
            return $this->credit($this->stripe->checkout->sessions->retrieve($sessionId), $user);
        } catch (\Throwable $e) {
            $this->logger->warning('Top-up fast path failed: {error}', ['error' => $e->getMessage()]);

            return TopUpCredit::NotCredited;
        }
    }
}

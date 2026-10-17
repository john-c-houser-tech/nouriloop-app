<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A canceled plan shows its end date on Settings ▸ Subscription (PR 8 test-mode run,
 * 2026-10-13: after canceling in the portal the page still read "Loop+" with nothing
 * to say the cancellation took). The cancellation arrives as a Stripe-signed
 * customer.subscription.updated carrying cancel_at; a late update or deletion for a
 * subscription the user has since replaced leaves the current one alone.
 */
final class SubscriptionEndDateTest extends WebTestCase
{
    use DbResetTrait;

    private const CUSTOMER = 'cus_end_date_test';
    private const SUBSCRIPTION = 'sub_end_date_test';
    private const LOOP_PLUS_PRICE = 'price_loop_plus_monthly_placeholder';

    public function testACanceledPlanShowsWhenItEnds(): void
    {
        [$client, $em, $user] = $this->subscriber();
        $ends = new \DateTimeImmutable('+20 days');

        $this->webhook($client, 'customer.subscription.updated', $this->subscription([
            'cancel_at' => $ends->getTimestamp(),
            'cancel_at_period_end' => false,
        ]));

        $em->refresh($user);
        self::assertSame($ends->getTimestamp(), $user->getSubscriptionCancelAt()?->getTimestamp());

        $client->request('GET', '/settings/subscription');
        self::assertSelectorTextContains('#subscription-ends', 'Canceled. You keep Loop+ until');
    }

    public function testARenewingPlanShowsNoEndDate(): void
    {
        [$client] = $this->subscriber();

        $this->webhook($client, 'customer.subscription.updated', $this->subscription([
            'cancel_at' => null,
            'cancel_at_period_end' => false,
        ]));

        $client->request('GET', '/settings/subscription');
        self::assertSelectorNotExists('#subscription-ends');
    }

    public function testDeletingAReplacedSubscriptionLeavesTheCurrentOneAlone(): void
    {
        [$client, $em, $user] = $this->subscriber();

        $this->webhook($client, 'customer.subscription.deleted', $this->subscription(['id' => 'sub_old_one']));

        $em->refresh($user);
        self::assertSame('active', $user->getSubscriptionStatus());
        self::assertSame(self::SUBSCRIPTION, $user->getStripeSubscriptionId());
    }

    public function testALateUpdateForAReplacedSubscriptionIsIgnored(): void
    {
        [$client, $em, $user] = $this->subscriber();

        $this->webhook($client, 'customer.subscription.updated', $this->subscription([
            'id' => 'sub_old_one',
            'status' => 'canceled',
            'cancel_at' => (new \DateTimeImmutable('+3 days'))->getTimestamp(),
        ]));

        $em->refresh($user);
        self::assertSame(self::SUBSCRIPTION, $user->getStripeSubscriptionId());
        self::assertSame('active', $user->getSubscriptionStatus());
        self::assertNull($user->getSubscriptionCancelAt());
    }

    public function testANewSubscriptionAfterTheOldOneEndedSyncs(): void
    {
        [$client, $em, $user] = $this->subscriber();
        $user->setSubscriptionStatus('canceled');
        $em->flush();

        $this->webhook($client, 'customer.subscription.created', $this->subscription(['id' => 'sub_new_one']));

        $em->refresh($user);
        self::assertSame('sub_new_one', $user->getStripeSubscriptionId());
        self::assertSame('active', $user->getSubscriptionStatus());
    }

    /** The message names the plan being canceled, not a comp that outlasts it. */
    public function testTheEndingPlanIsTheBilledOne(): void
    {
        [$client, $em, $user] = $this->subscriber();
        $user->setSubscriptionTier(SubscriptionTier::Loop)
            ->setCompTier(SubscriptionTier::LoopPlus)
            ->setSubscriptionCancelAt(new \DateTimeImmutable('+20 days'));
        $em->flush();

        $client->request('GET', '/settings/subscription');
        self::assertSelectorTextContains('#subscription-ends', 'You keep Loop until');
    }

    /**
     * @param array<string, mixed> $with
     * @return array<string, mixed>
     */
    private function subscription(array $with = []): array
    {
        return array_merge([
            'id' => self::SUBSCRIPTION,
            'object' => 'subscription',
            'customer' => self::CUSTOMER,
            'status' => 'active',
            'current_period_end' => (new \DateTimeImmutable('+20 days'))->getTimestamp(),
            'items' => ['object' => 'list', 'data' => [['price' => ['id' => self::LOOP_PLUS_PRICE]]]],
        ], $with);
    }

    /** @param array<string, mixed> $object */
    private function webhook(KernelBrowser $client, string $type, array $object): void
    {
        $payload = (string) json_encode([
            'id' => 'evt_' . bin2hex(random_bytes(6)),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);
        $timestamp = time();
        $secret = (string) ($_ENV['STRIPE_WEBHOOK_SECRET'] ?? $_SERVER['STRIPE_WEBHOOK_SECRET']);
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        $client->request(
            'POST',
            '/webhook/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
            content: $payload,
        );
        self::assertResponseIsSuccessful();
    }

    /** @return array{KernelBrowser, EntityManagerInterface, User} */
    private function subscriber(): array
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = new User();
        $user->setEmail('ender@test.local');
        $user->setDisplayName('Ender');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setOnboardingDismissed(true);
        $user->setStripeCustomerId(self::CUSTOMER);
        $user->setStripeSubscriptionId(self::SUBSCRIPTION);
        $user->setSubscriptionTier(SubscriptionTier::LoopPlus);
        $user->setSubscriptionStatus('active');
        $user->setSubscriptionPeriodEnd(new \DateTimeImmutable('+20 days'));
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        return [$client, $em, $user];
    }
}

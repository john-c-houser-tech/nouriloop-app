<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\AllowanceTopUp;
use App\Entity\User;
use App\Enum\SubscriptionTier;
use App\Enum\TopUpCredit;
use App\Service\AccountDeletionService;
use App\Service\AllowanceTopUpService;
use App\Service\AnalysisAllowance;
use App\Service\UserTime;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Checkout\Session;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The Loop+ allowance top-up (App Development Plan PR 7b, decision Q3): who reaches the
 * page, and how Stripe's webhook credits a paid top-up — once, for the right user and
 * month, and never for anything that isn't a paid top-up.
 */
final class AllowanceTopUpTest extends WebTestCase
{
    use DbResetTrait;

    private const CUSTOMER = 'cus_topup_test';

    public function testLoopPlusSeesItsAnalysesAndTheTopUp(): void
    {
        [$client] = $this->signedIn(SubscriptionTier::LoopPlus);

        $crawler = $client->request('GET', '/allowance');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('120 of 120 left', $crawler->filter('#allowance-status')->text());
        self::assertStringContainsString('Add 60 analyses — $1.75', $crawler->filter('#allowance-top-up')->text());
        self::assertSame(1, $crawler->filter('#allowance-own-key a[href="/settings/ai"]')->count());
    }

    public function testEveryoneElseIsSentWhereTheirMoreComesFrom(): void
    {
        [$client] = $this->signedIn(null);
        $client->request('GET', '/allowance');
        self::assertResponseRedirects('/pricing');

        [$client] = $this->signedIn(SubscriptionTier::Loop);
        $client->request('GET', '/allowance');
        self::assertResponseRedirects('/settings/ai');
    }

    public function testAPaidTopUpIsCreditedOnceForTheMonthItWasBoughtFor(): void
    {
        [$client, $em, $user] = $this->signedIn(SubscriptionTier::LoopPlus);
        $month = static::getContainer()->get(UserTime::class)->now($user)->format('Y-m');

        $this->webhook($client, $this->session('cs_paid', ['month' => $month]));
        $this->webhook($client, $this->session('cs_paid', ['month' => $month])); // Stripe retries

        self::assertResponseIsSuccessful();
        $rows = $em->getRepository(AllowanceTopUp::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame($month, $rows[0]->getMonth());
        self::assertSame(
            AnalysisAllowance::INCLUDED_MONTHLY_LIMIT + AnalysisAllowance::TOP_UP_ANALYSES,
            static::getContainer()->get(AnalysisAllowance::class)->limit($user),
        );

        $crawler = $client->request('GET', '/allowance');
        self::assertStringContainsString('180 of 180 left', $crawler->filter('#allowance-status')->text());
    }

    /** The success page shows "added" when the webhook got there first (Copilot catch on #248). */
    public function testASecondCreditReportsAlreadyCredited(): void
    {
        [, , $user] = $this->signedIn(SubscriptionTier::LoopPlus);
        $service = static::getContainer()->get(AllowanceTopUpService::class);
        $session = Session::constructFrom($this->session('cs_twice'));

        self::assertSame(TopUpCredit::Credited, $service->credit($session, $user));
        self::assertSame(TopUpCredit::AlreadyCredited, $service->credit($session, $user));
        self::assertTrue(TopUpCredit::AlreadyCredited->isApplied());
        $unpaid = Session::constructFrom($this->session('cs_unpaid2', ['payment_status' => 'unpaid']));
        self::assertSame(TopUpCredit::NotCredited, $service->credit($unpaid, $user));
    }

    /** A failed credit is not acknowledged, so Stripe retries (Copilot catch on #248). */
    public function testAFailedCreditAsksStripeToRetry(): void
    {
        [$client, $em] = $this->signedIn(SubscriptionTier::LoopPlus);
        $conn = $em->getConnection();
        $conn->executeStatement('RENAME TABLE allowance_top_up TO allowance_top_up_away');
        try {
            $this->webhook($client, $this->session('cs_db_down'), expectStatus: 500);
        } finally {
            $conn->executeStatement('RENAME TABLE allowance_top_up_away TO allowance_top_up');
        }

        // Stripe's retry then credits it.
        self::ensureKernelShutdown();
        [$client, $em] = $this->signedIn(SubscriptionTier::LoopPlus);
        $this->webhook($client, $this->session('cs_db_down'));
        self::assertCount(1, $em->getRepository(AllowanceTopUp::class)->findAll());
    }

    public function testOnlyPaidTopUpsAreCredited(): void
    {
        [$client, $em] = $this->signedIn(SubscriptionTier::LoopPlus);

        $this->webhook($client, $this->session('cs_unpaid', ['payment_status' => 'unpaid']));
        $this->webhook($client, $this->session('cs_subscription', ['purpose' => null, 'mode' => 'subscription']));
        $this->webhook($client, $this->session('cs_stranger', ['customer' => 'cus_someone_else']));

        self::assertCount(0, $em->getRepository(AllowanceTopUp::class)->findAll());
    }

    public function testPurgingTheAccountRemovesItsTopUps(): void
    {
        [$client, $em, $user] = $this->signedIn(SubscriptionTier::LoopPlus);
        $this->webhook($client, $this->session('cs_purge'));
        self::assertCount(1, $em->getRepository(AllowanceTopUp::class)->findAll());

        static::getContainer()->get(AccountDeletionService::class)->purgeUser($user);

        $em->clear();
        self::assertCount(0, $em->getRepository(AllowanceTopUp::class)->findAll());
    }

    /**
     * A checkout.session.completed event as Stripe sends it, with overrides.
     *
     * @param array<string, string|null> $with
     *
     * @return array<string, mixed>
     */
    private function session(string $id, array $with = []): array
    {
        $metadata = ['purpose' => 'allowance_top_up', 'month' => '2026-10'];
        foreach (['purpose', 'month'] as $key) {
            if (\array_key_exists($key, $with)) {
                $metadata[$key] = $with[$key];
            }
        }

        return [
            'id' => $id,
            'object' => 'checkout.session',
            'mode' => $with['mode'] ?? 'payment',
            'payment_status' => $with['payment_status'] ?? 'paid',
            'customer' => $with['customer'] ?? self::CUSTOMER,
            'metadata' => array_filter($metadata, static fn(?string $v): bool => $v !== null),
        ];
    }

    /** @param array<string, mixed> $session */
    private function webhook(KernelBrowser $client, array $session, int $expectStatus = 200): void
    {
        $payload = (string) json_encode([
            'id' => 'evt_' . $session['id'],
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => $session],
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
        self::assertResponseStatusCodeSame($expectStatus);
    }

    /** @return array{KernelBrowser, EntityManagerInterface, User} */
    private function signedIn(?SubscriptionTier $comp): array
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = new User();
        $user->setEmail('topper@test.local');
        $user->setDisplayName('Topper');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setOnboardingDismissed(true);
        $user->setStripeCustomerId(self::CUSTOMER);
        if ($comp !== null) {
            $user->setCompTier($comp);
        }
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        return [$client, $em, $user];
    }
}

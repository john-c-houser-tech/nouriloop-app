<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * One subscription per customer. A subscriber who picks the other plan on the pricing
 * page is sent to the Billing Portal's change-plan screen, never to a second Checkout:
 * in the PR 8 test-mode run (2026-10-13) a Loop subscriber who chose Loop+ ended up
 * with two live subscriptions, billed for both. Stripe is a canned HTTP client that
 * records which endpoints the app called.
 */
final class PlanSwitchTest extends WebTestCase
{
    use DbResetTrait;

    /** @var list<string> Stripe API paths the app called */
    private array $requested = [];

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    public function testASubscriberSwitchesPlanInThePortal(): void
    {
        $client = $this->signedIn(subscriptionStatus: 'trialing');

        $crawler = $client->request('GET', '/pricing');
        self::assertSelectorTextContains('#plan-loop-plus', 'Switch to this plan');
        self::assertSelectorTextContains('#plan-loop', 'Your current plan');

        $client->submit($crawler->filter('#plan-loop-plus form')->form());

        self::assertResponseRedirects('https://billing.stripe.test/p/session');
        self::assertSame(['/v1/billing_portal/sessions'], $this->requested);
    }

    public function testACanceledSubscriberChecksOutAgain(): void
    {
        $client = $this->signedIn(subscriptionStatus: 'canceled');

        $crawler = $client->request('GET', '/pricing');
        self::assertSelectorTextContains('#plan-loop-plus', 'Get Loop+');

        $client->submit($crawler->filter('#plan-loop-plus form')->form());

        self::assertResponseRedirects('https://checkout.stripe.test/c/session');
        self::assertSame(['/v1/checkout/sessions'], $this->requested);
    }

    /** A comp lifts the effective tier; the cards still follow the plan Stripe bills. */
    public function testACompedSubscriberSeesTheBilledPlanAsCurrent(): void
    {
        $client = $this->signedIn(subscriptionStatus: 'active', comp: SubscriptionTier::LoopPlus);

        $client->request('GET', '/pricing');

        self::assertSelectorTextContains('#plan-loop', 'Your current plan');
        self::assertSelectorTextContains('#plan-loop-plus', 'Switch to this plan');
    }

    private function signedIn(string $subscriptionStatus, ?SubscriptionTier $comp = null): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = new User();
        $user->setEmail('switcher@test.local');
        $user->setDisplayName('Switcher');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setOnboardingDismissed(true);
        $user->setStripeCustomerId('cus_switch_test');
        $user->setStripeSubscriptionId('sub_switch_test');
        $user->setSubscriptionTier(SubscriptionTier::Loop);
        $user->setSubscriptionStatus($subscriptionStatus);
        $user->setSubscriptionPeriodEnd(new \DateTimeImmutable('+5 days'));
        $user->setCompTier($comp);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        $requested = &$this->requested;
        ApiRequestor::setHttpClient(new class ($requested) implements ClientInterface {
            /** @param list<string> $requested */
            public function __construct(private array &$requested)
            {
            }

            public function request(
                $method,
                $absUrl,
                $headers,
                $params,
                $hasFile,
                $apiMode = 'v1',
                $maxNetworkRetries = null,
            ) {
                $path = (string) parse_url($absUrl, PHP_URL_PATH);
                $this->requested[] = $path;

                return match ($path) {
                    '/v1/billing_portal/sessions' => [json_encode([
                        'id' => 'bps_1', 'object' => 'billing_portal.session',
                        'url' => 'https://billing.stripe.test/p/session',
                    ]), 200, []],
                    '/v1/checkout/sessions' => [json_encode([
                        'id' => 'cs_1', 'object' => 'checkout.session',
                        'url' => 'https://checkout.stripe.test/c/session',
                    ]), 200, []],
                    default => [json_encode(['error' => ['message' => 'No such resource']]), 404, []],
                };
            }
        });

        return $client;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Symfony\Component\Clock\MockClock;

/**
 * The success page's `?session_id=` fast-path sync. The session id comes from a
 * query string, so the Checkout session must belong to the user's own Stripe
 * customer before its subscription is stamped onto their row. A real StripeClient
 * runs against a canned HTTP client (the SDK's own ApiRequestor hook), so the
 * retrieve → compare → sync path is exercised end to end with no network.
 */
final class SubscriptionServiceCheckoutSyncTest extends TestCase
{
    private const LOOP_PLUS_MONTHLY = 'price_loop_plus_monthly';

    /** @var list<string> request paths the fake Stripe API was asked for */
    private array $requested = [];

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    public function testSessionOfTheUsersOwnCustomerSyncs(): void
    {
        $user = (new User())->setStripeCustomerId('cus_owner');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $this->service($em, sessionCustomer: 'cus_owner')->syncFromCheckoutSession($user, 'cs_1');

        self::assertSame(SubscriptionTier::LoopPlus, $user->getSubscriptionTier());
        self::assertSame('sub_1', $user->getStripeSubscriptionId());
    }

    public function testAnotherCustomersSessionIsIgnored(): void
    {
        $user = (new User())->setStripeCustomerId('cus_attacker');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->service($em, sessionCustomer: 'cus_victim')->syncFromCheckoutSession($user, 'cs_1');

        self::assertSame(SubscriptionTier::Free, $user->getSubscriptionTier());
        self::assertNull($user->getStripeSubscriptionId());
        // Stops at the session: the victim's subscription is never even fetched.
        self::assertSame(['/v1/checkout/sessions/cs_1'], $this->requested);
    }

    public function testUserWithoutACustomerIsIgnored(): void
    {
        $user = new User();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->service($em, sessionCustomer: 'cus_victim')->syncFromCheckoutSession($user, 'cs_1');

        self::assertNull($user->getStripeSubscriptionId());
    }

    private function service(EntityManagerInterface $em, string $sessionCustomer): SubscriptionService
    {
        $responses = [
            '/v1/checkout/sessions/cs_1' => [
                'id' => 'cs_1',
                'object' => 'checkout.session',
                'customer' => $sessionCustomer,
                'subscription' => 'sub_1',
            ],
            '/v1/subscriptions/sub_1' => [
                'id' => 'sub_1',
                'object' => 'subscription',
                'customer' => $sessionCustomer,
                'status' => 'active',
                'current_period_end' => 1_750_000_000,
                'items' => ['object' => 'list', 'data' => [['price' => ['id' => self::LOOP_PLUS_MONTHLY]]]],
            ],
        ];

        $requested = &$this->requested;
        ApiRequestor::setHttpClient(new class ($responses, $requested) implements ClientInterface {
            /**
             * @param array<string, array<string, mixed>> $responses
             * @param list<string>                         $requested
             */
            public function __construct(private readonly array $responses, private array &$requested)
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

                return isset($this->responses[$path])
                    ? [json_encode($this->responses[$path]), 200, []]
                    : [json_encode(['error' => ['message' => 'No such resource']]), 404, []];
            }
        });

        return new SubscriptionService(
            new MockClock('2026-06-01 00:00:00'),
            new StripeClient(['api_key' => 'sk_test_fake']),
            $em,
            null,
            'price_loop_monthly',
            'price_loop_yearly',
            self::LOOP_PLUS_MONTHLY,
            'price_loop_plus_yearly',
        );
    }
}

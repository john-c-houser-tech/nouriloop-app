<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Symfony\Component\Clock\MockClock;

/**
 * The Billing Portal session: a plain "Manage subscription" opens the portal's home,
 * and a plan switch opens its change-plan screen for the user's own subscription
 * (the fix for the double subscription found in the PR 8 test-mode run). A real
 * StripeClient runs against a canned HTTP client, as in SubscriptionServiceCheckoutSyncTest.
 */
final class SubscriptionServicePortalTest extends TestCase
{
    private const RETURN_URL = 'https://app.test/settings/subscription';

    /** @var list<array<string, mixed>> params of each portal session the fake Stripe API was asked for */
    private array $sessions = [];

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    public function testManageOpensThePortalHome(): void
    {
        $user = (new User())->setStripeCustomerId('cus_1')->setStripeSubscriptionId('sub_1');

        $url = $this->service()->createPortalSession($user, self::RETURN_URL);

        self::assertSame('https://billing.stripe.test/p/session', $url);
        self::assertSame(['customer' => 'cus_1', 'return_url' => self::RETURN_URL], $this->sessions[0]);
    }

    public function testSwitchPlanOpensTheChangePlanScreenForTheUsersSubscription(): void
    {
        $user = (new User())->setStripeCustomerId('cus_1')->setStripeSubscriptionId('sub_1')
            ->setSubscriptionStatus('trialing');

        $this->service()->createPortalSession($user, self::RETURN_URL, switchPlan: true);

        $flow = $this->sessions[0]['flow_data'] ?? null;
        self::assertIsArray($flow);
        self::assertSame('subscription_update', $flow['type']);
        self::assertSame(['subscription' => 'sub_1'], $flow['subscription_update']);
        self::assertSame(
            ['type' => 'redirect', 'redirect' => ['return_url' => self::RETURN_URL]],
            $flow['after_completion'],
        );
    }

    public function testSwitchPlanWithoutASubscriptionFallsBackToThePortalHome(): void
    {
        $user = (new User())->setStripeCustomerId('cus_1');

        $this->service()->createPortalSession($user, self::RETURN_URL, switchPlan: true);

        self::assertArrayNotHasKey('flow_data', $this->sessions[0]);
    }

    /** Stripe can't change plan on an unpaid first invoice: the user finishes paying first. */
    public function testAnIncompleteSubscriptionGetsThePortalHome(): void
    {
        $user = (new User())->setStripeCustomerId('cus_1')->setStripeSubscriptionId('sub_1')
            ->setSubscriptionStatus('incomplete');

        $this->service()->createPortalSession($user, self::RETURN_URL, switchPlan: true);

        self::assertArrayNotHasKey('flow_data', $this->sessions[0]);
    }

    private function service(): SubscriptionService
    {
        $sessions = &$this->sessions;
        ApiRequestor::setHttpClient(new class ($sessions) implements ClientInterface {
            /** @param list<array<string, mixed>> $sessions */
            public function __construct(private array &$sessions)
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
                if ((string) parse_url($absUrl, PHP_URL_PATH) !== '/v1/billing_portal/sessions') {
                    return [json_encode(['error' => ['message' => 'No such resource']]), 404, []];
                }
                $this->sessions[] = $params;

                return [json_encode([
                    'id' => 'bps_1',
                    'object' => 'billing_portal.session',
                    'url' => 'https://billing.stripe.test/p/session',
                ]), 200, []];
            }
        });

        return new SubscriptionService(
            new MockClock('2026-10-13 00:00:00'),
            new StripeClient(['api_key' => 'sk_test_fake']),
            $this->createStub(EntityManagerInterface::class),
            null,
            'price_loop_monthly',
            'price_loop_plus_monthly',
        );
    }
}

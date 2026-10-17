<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The public pricing page renders both tiers, and the Stripe webhook rejects an
 * unsigned/forged payload before doing any work. In-process WebTestCase (no DB needed —
 * pricing is static Twig and the signature check fails first), kept under tests/E2E so
 * it runs in the e2e CI job alongside the other in-process functional tests.
 */
final class SubscriptionPagesTest extends WebTestCase
{
    public function testPricingPageIsPublicAndShowsBothTiers(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pricing');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Loop');
        self::assertSelectorTextContains('body', 'Loop+');
        self::assertSelectorTextContains('body', '$2');
        self::assertSelectorTextContains('body', '$13.50');
    }

    public function testWebhookRejectsBadSignature(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/webhook/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef'],
            content: '{"id":"evt_1","type":"customer.subscription.created"}',
        );

        self::assertResponseStatusCodeSame(400);
    }
}

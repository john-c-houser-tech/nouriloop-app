<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GA4 / Meta Pixel / cookie-consent guardrails. The safety-critical property is the
 * kill-switch: with no GOOGLE_ANALYTICS_ID / META_PIXEL_ID (the case in dev/test/CI),
 * no analytics script, no pixel script, and no cookie banner are emitted at all. The
 * "present when configured" path is verified manually (the compiled env vars can't be
 * flipped per-test). The Pixel's additional guardrail — that it never loads on any
 * authenticated page even when configured — is also a manual check for the same reason.
 */
final class AnalyticsTest extends WebTestCase
{
    public function testAnalyticsAndBannerSuppressedWhenIdIsEmpty(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('googletagmanager.com', $html, 'no GA script without an id');
        self::assertStringNotContainsString('gtag(', $html, 'no gtag calls without an id');
        self::assertStringNotContainsString('id="cookie-consent"', $html, 'no cookie banner without analytics');
    }

    public function testMetaPixelSuppressedWhenIdIsEmpty(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('connect.facebook.net', $html, 'no pixel script without an id');
        self::assertStringNotContainsString('fbq(', $html, 'no fbq calls without an id');
        self::assertStringNotContainsString('facebook.com/tr', $html, 'no noscript pixel fallback without an id');
    }

    public function testTermsDisclosesAnalyticsAndCookies(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/privacy/terms');

        self::assertResponseIsSuccessful();
        // The cookie banner links here (#analytics); the section must exist.
        self::assertSame(1, $crawler->filter('#analytics')->count());
        self::assertSelectorTextContains('#analytics', 'Analytics and cookies');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('send your health data', $html);
        self::assertStringContainsString('Meta Pixel', $html);
        self::assertStringContainsString('never loaded on any page that displays your health information', $html);
    }
}

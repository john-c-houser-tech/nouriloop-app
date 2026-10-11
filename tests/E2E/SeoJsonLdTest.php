<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Controller\HelpController;
use App\Enum\SubscriptionTier;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Phase 2 of the SEO plan: JSON-LD structured data on the public pages. Asserts each
 * page emits a well-formed block of the right @type, that the /about offers are driven
 * by the shared SubscriptionTier price source (not a hardcoded copy), and that every
 * help topic produces a valid TechArticle.
 */
final class SeoJsonLdTest extends WebTestCase
{
    public function testAboutEmitsWebApplicationWithSharedPrices(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/about');
        self::assertResponseIsSuccessful();

        $data = $this->jsonLd($crawler);
        self::assertSame('WebApplication', $data['@type']);
        self::assertSame('HealthApplication', $data['applicationCategory']);
        self::assertNotEmpty($data['offers']);

        // The Loop offer's price must equal the shared SubscriptionTier source.
        $loop = array_values(array_filter($data['offers'], static fn (array $o): bool => $o['name'] === 'Loop'));
        self::assertCount(1, $loop);
        self::assertSame((string) SubscriptionTier::Loop->monthlyPriceUsd(), $loop[0]['price']);
        // priceSpecification is the schema.org-correct recurring shape (no bare billingIncrement).
        self::assertSame('UnitPriceSpecification', $loop[0]['priceSpecification']['@type']);
    }

    public function testHelpIndexEmitsItemListOfEveryTopic(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/help');
        self::assertResponseIsSuccessful();

        $data = $this->jsonLd($crawler);
        self::assertSame('ItemList', $data['@type']);
        self::assertCount(count(HelpController::TOPICS), $data['itemListElement']);

        $names = array_column($data['itemListElement'], 'name');
        foreach (HelpController::TOPICS as $meta) {
            self::assertContains($meta['title'], $names);
        }
    }

    public function testEveryHelpTopicEmitsAValidTechArticle(): void
    {
        $client = static::createClient();

        foreach (HelpController::TOPICS as $slug => $meta) {
            $crawler = $client->request('GET', '/help/' . $slug);
            self::assertResponseIsSuccessful("help topic $slug should render");

            $data = $this->jsonLd($crawler);
            self::assertSame('TechArticle', $data['@type'], "topic $slug @type");
            self::assertSame($meta['title'], $data['headline'], "topic $slug headline");
            self::assertStringContainsString('/images/help/' . $slug . '.png', $data['image']);
        }
    }

    /** @return array<string, mixed> the page's first JSON-LD block, decoded */
    private function jsonLd(Crawler $crawler): array
    {
        $node = $crawler->filter('script[type="application/ld+json"]');
        self::assertGreaterThan(0, $node->count(), 'page should emit a JSON-LD block');

        $decoded = json_decode($node->first()->text(), true);
        self::assertIsArray($decoded, 'JSON-LD must be valid JSON');

        return $decoded;
    }
}

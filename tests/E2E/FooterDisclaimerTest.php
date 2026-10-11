<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Content\WellnessDisclaimers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Every page footer renders one of the rotating long-set wellness disclaimers
 * above the copyright. In-process WebTestCase against the public login page (no DB
 * needed — the footer is in base.html.twig and the disclaimer is computed in PHP),
 * kept under tests/E2E so it runs in the e2e CI job alongside the other in-process
 * functional tests.
 */
final class FooterDisclaimerTest extends WebTestCase
{
    public function testFooterShowsALongDisclaimerAboveTheCopyright(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();

        $disclaimer = trim($crawler->filter('footer .wellness-disclaimer')->text());
        self::assertNotSame('', $disclaimer);
        self::assertContains(
            $disclaimer,
            WellnessDisclaimers::set(WellnessDisclaimers::LONG),
            'Footer disclaimer should be one of the curated long-set entries.',
        );

        // Copyright still present.
        self::assertStringContainsString('NouriLoop LLC', $crawler->filter('footer')->text());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The public /about page. In-process WebTestCase (no DB needed — static Twig), kept
 * under tests/E2E so it runs in the e2e CI job alongside the other in-process
 * functional tests (see HelpPagesTest).
 */
final class AboutPageTest extends WebTestCase
{
    public function testCarriesThePublicCampaignerBreadcrumb(): void
    {
        // Google's Ads API compliance reviewers check the public site for content
        // related to the application — this sentence is the indexable breadcrumb.
        $client = static::createClient();
        $client->request('GET', '/about');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('developer of <strong>Campaigner</strong>', $body);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Controller\HelpController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The help center is public (no login) and every registered topic renders; unknown
 * topics 404. In-process WebTestCase (no DB needed — the pages are static Twig), kept
 * under tests/E2E so it runs in the **e2e CI job** (which includes tests/E2E), alongside
 * the other in-process functional tests (WelcomeEmailTest, BetaGrandfatheringTest) — the
 * unit job excludes tests/E2E and has no DB. It IS covered by CI, just not the unit job.
 */
final class HelpPagesTest extends WebTestCase
{
    public function testIndexIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/help');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'NouriLoop Help');
    }

    public function testEveryTopicRendersAnonymously(): void
    {
        $client = static::createClient();

        foreach (array_keys(HelpController::TOPICS) as $slug) {
            $client->request('GET', '/help/' . $slug);
            self::assertResponseIsSuccessful(sprintf('help topic "%s" should render', $slug));
        }
    }

    public function testUnknownTopicIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/help/does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }
}

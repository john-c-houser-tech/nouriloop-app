<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Controller\HelpController;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SEO foundations: robots.txt + sitemap.xml are public and well-formed, public pages
 * carry the SEO/social meta, and authenticated pages are noindex'd. In-process
 * WebTestCase (kept in tests/E2E so it runs in the DB-having e2e job — the
 * authenticated-noindex case needs a real user).
 */
final class SeoTest extends WebTestCase
{
    use DbResetTrait;

    public function testRobotsTxtIsPublicAndPointsAtTheSitemap(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('User-agent: *', $body);
        self::assertStringContainsString('Disallow: /meal/', $body);
        self::assertStringContainsString('Sitemap: ', $body);
        self::assertStringContainsString('/sitemap.xml', $body);
        // The dashboard lives at "/", so there must be no bogus "/dashboard" rule.
        self::assertStringNotContainsString('Disallow: /dashboard', $body);
    }

    public function testSitemapIsValidXmlAndListsPublicPages(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        $contentType = (string) $client->getResponse()->headers->get('Content-Type');
        self::assertStringContainsString('application/xml', $contentType);

        $xml = simplexml_load_string((string) $client->getResponse()->getContent());
        self::assertNotFalse($xml, 'sitemap must be well-formed XML');

        $locs = [];
        foreach ($xml->url as $url) {
            $locs[] = (string) $url->loc;
        }
        $joined = implode("\n", $locs);

        self::assertStringContainsString('/about', $joined);
        self::assertStringContainsString('/pricing', $joined);
        // Every help topic is present (driven by HelpController::TOPICS).
        foreach (array_keys(HelpController::TOPICS) as $slug) {
            self::assertStringContainsString('/help/' . $slug, $joined, "sitemap should list help topic $slug");
        }
    }

    public function testAboutCarriesSeoMeta(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/about');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'AI-Powered Nutrition Tracking');
        self::assertSame(1, $crawler->filter('meta[name="description"]')->count());
        self::assertStringContainsString(
            'inflammation score',
            $crawler->filter('meta[name="description"]')->attr('content'),
        );
        self::assertStringContainsString('/about', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertStringContainsString('og-card.png', $crawler->filter('meta[property="og:image"]')->attr('content'));
        self::assertStringContainsString(
            'AI-Powered Nutrition Tracking',
            $crawler->filter('meta[property="og:title"]')->attr('content'),
        );
        // A public marketing page must be indexable.
        self::assertSame(0, $crawler->filter('meta[name="robots"][content="noindex"]')->count());
    }

    public function testAuthenticatedPageIsNoindex(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'seo@test.local'));

        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSame(
            1,
            $crawler->filter('meta[name="robots"][content="noindex"]')->count(),
            'authenticated pages must be noindex',
        );
    }

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Seeded User');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}

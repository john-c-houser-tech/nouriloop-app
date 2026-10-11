<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Enum\SupportedLocale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * i18n Phase 4 — locale-prefixed public conversion pages (/es/about, …):
 * routing + URL-locale precedence, hreflang/canonical wiring, sitemap
 * variants, localized cross-links, and register-form locale continuity.
 * Content assertions stay language-structural (lang attrs, URLs) — the
 * curated wording is CatalogConsistencyTest + review territory.
 */
final class LocalizedPublicPagesTest extends WebTestCase
{
    use DbResetTrait;

    public function testPrefixedPagesRenderInTheUrlLocaleAnonymously(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        foreach (['es' => 'es', 'es-mx' => 'es-MX', 'de' => 'de', 'hi' => 'hi'] as $prefix => $lang) {
            $crawler = $client->request('GET', "/{$prefix}/about");
            self::assertResponseIsSuccessful("/{$prefix}/about");
            self::assertSame($lang, $crawler->filter('html')->attr('lang'), $prefix);
        }

        // Bare URL stays English — no redirect games.
        $crawler = $client->request('GET', '/about');
        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));

        // Junk prefixes are 404s, not English fallbacks (whitelisted requirement).
        $client->request('GET', '/xx/about');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUrlLocaleBeatsAccountPreference(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'urlwins@test.local', SupportedLocale::Spanish);
        $client->loginUser($user);

        // A signed-in es user opening the German variant sees German — the
        // shareable URL is an explicit choice and Google crawls it as-is.
        $crawler = $client->request('GET', '/de/about');
        self::assertResponseIsSuccessful();
        self::assertSame('de', $crawler->filter('html')->attr('lang'));

        // Bare public URL falls back to their saved preference.
        $crawler = $client->request('GET', '/about');
        self::assertSame('es', $crawler->filter('html')->attr('lang'));
    }

    public function testHreflangAlternatesAndSelfCanonicalOnScopedPages(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $crawler = $client->request('GET', '/es/about');
        self::assertResponseIsSuccessful();

        // Self-canonical (the variant, not the bare URL).
        $canonical = $crawler->filter('link[rel="canonical"]')->attr('href');
        self::assertStringEndsWith('/es/about', (string) $canonical);

        // x-default + en point at the bare URL; every locale variant present.
        $alternates = [];
        foreach ($crawler->filter('link[rel="alternate"][hreflang]') as $node) {
            $alternates[$node->getAttribute('hreflang')] = $node->getAttribute('href');
        }
        self::assertStringEndsWith('/about', $alternates['x-default'] ?? '');
        self::assertStringNotContainsString('/es/', $alternates['x-default'] ?? '');
        self::assertSame($alternates['x-default'] ?? null, $alternates['en'] ?? null);
        foreach (['es', 'es-MX', 'es-ES', 'en-GB', 'de', 'fr', 'ru', 'hi'] as $tag) {
            self::assertArrayHasKey($tag, $alternates, "hreflang {$tag} missing");
        }
        self::assertStringEndsWith('/es-mx/about', $alternates['es-MX']);

        // The bare English page carries the same alternate set.
        $crawler = $client->request('GET', '/about');
        self::assertGreaterThanOrEqual(
            9,
            $crawler->filter('link[rel="alternate"][hreflang]')->count(),
            'bare page must emit the alternates too',
        );

        // Landing pages are noindex — no hreflang there.
        $crawler = $client->request('GET', '/lp/fiber');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('link[rel="alternate"][hreflang]')->count());
    }

    public function testSitemapListsLocaleVariantsOnceEach(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        $xml = (string) $client->getResponse()->getContent();

        // Count exact URL paths — a substring count would over-match ("/about"
        // is a suffix of every "/{locale}/about" variant).
        $doc = simplexml_load_string($xml);
        self::assertNotFalse($doc, 'sitemap must be well-formed XML');
        $paths = [];
        foreach ($doc->url as $url) {
            $paths[] = (string) parse_url((string) $url->loc, PHP_URL_PATH);
        }
        $counts = array_count_values($paths);
        foreach (['/about', '/es/about', '/es-mx/about', '/hi/about', '/es/pricing', '/ru/pricing'] as $path) {
            self::assertSame(1, $counts[$path] ?? 0, $path);
        }
        // Noindex'd landing pages stay out in every locale.
        self::assertStringNotContainsString('/lp/', $xml);
    }

    public function testPrefixedPageCrossLinksKeepTheLocale(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // The es landing page's only path forward (register CTA) stays Spanish.
        $crawler = $client->request('GET', '/es/lp/fiber');
        self::assertResponseIsSuccessful();
        $hrefs = array_map(
            static fn ($node) => $node->getAttribute('href'),
            iterator_to_array($crawler->filter('a[href^="/"]')),
        );
        self::assertNotContains('/register', $hrefs, 'CTA must not drop the locale');
        self::assertContains('/es/register', $hrefs);

        // And the bare page still links bare.
        $crawler = $client->request('GET', '/lp/fiber');
        $hrefs = array_map(
            static fn ($node) => $node->getAttribute('href'),
            iterator_to_array($crawler->filter('a[href^="/"]')),
        );
        self::assertContains('/register', $hrefs);
    }

    public function testLocalizedLoginRoundTripAuthenticatesAndKeepsLocaleOnFailure(): void
    {
        // The form authenticator only listens on the bare /login check path, so
        // the localized page's form must post there explicitly — without an
        // action the POST re-rendered /es/login unauthenticated (Copilot catch
        // on PR #157). Failure returns to the localized page via _failure_path.
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $this->seedUser($em, 'eslogin@test.local', SupportedLocale::Spanish);

        // Wrong password: back to the LOCALIZED login page, still Spanish.
        $crawler = $client->request('GET', '/es/login');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/login"]')->first()->form([
            '_username' => 'eslogin@test.local',
            '_password' => 'wrong-password',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/es/login');
        $crawler = $client->followRedirect();
        self::assertSame('es', $crawler->filter('html')->attr('lang'), 'failure keeps the URL locale');

        // Right password from the localized page: authenticated as usual.
        $form = $crawler->filter('form[action="/login"]')->first()->form([
            '_username' => 'eslogin@test.local',
            '_password' => 'password123',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/');
    }

    public function testRegisteringOnAPrefixedPageCarriesItsLocale(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // Accept-Language says German, but the page is /es-mx/register — the
        // URL's explicit language wins for the new account.
        $client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'de-DE,de;q=0.9');
        $crawler = $client->request('GET', '/es-mx/register');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="registration_form"]')->form([
            'registration_form[displayName]' => 'Url Locale',
            'registration_form[email]' => 'urllocale@test.local',
            'registration_form[invitationCode]' => '',
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'urllocale@test.local']);
        self::assertNotNull($user);
        self::assertSame(SupportedLocale::SpanishMexico, $user->getLocale());
    }

    private function seedUser(EntityManagerInterface $em, string $email, SupportedLocale $locale): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Phase Four');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setLocale($locale);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $hasher = static::getContainer()
            ->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}

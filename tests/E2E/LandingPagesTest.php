<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Controller\LandingPageController;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The ad landing pages (/lp/{slug}, Marketing Plan §8) are public, noindex'd,
 * navigation-free, and lead to the register CTA; unknown slugs 404 and the
 * sitemap never lists them. In-process WebTestCase (static Twig, no DB writes),
 * kept under tests/E2E so it runs in the e2e CI job — same arrangement as
 * HelpPagesTest.
 */
final class LandingPagesTest extends WebTestCase
{
    use DbResetTrait;

    public function testEveryPageRendersAnonymouslyWithNoindexAndRegisterCtaOnly(): void
    {
        $client = static::createClient();

        foreach (array_keys(LandingPageController::PAGES) as $slug) {
            $crawler = $client->request('GET', '/lp/' . $slug);

            self::assertResponseIsSuccessful(sprintf('landing page "%s" should render', $slug));
            self::assertSelectorExists(
                'meta[name="robots"][content="noindex"]',
                sprintf('landing page "%s" must be noindex (paid-traffic page)', $slug)
            );
            $this->assertRegisterIsTheOnlyLink($crawler->filter('a'), $slug);
        }
    }

    public function testSignedInVisitorGetsNoNavigationChromeEither(): void
    {
        // A logged-in user clicking an ad must land on the same CTA-only page: the
        // base layout's chrome_suppressed flag hides the navbar, banners, floating
        // help button, onboarding overlay, and the footer feedback link on /lp/*.
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'lp-visitor@test.local'));

        $crawler = $client->request('GET', '/lp/inflammation');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('nav.navbar', 'the signed-in navbar must be suppressed on /lp/*');
        $this->assertRegisterIsTheOnlyLink($crawler->filter('a'), 'inflammation (signed in)');
    }

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Seeded User');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** @param \Symfony\Component\DomCrawler\Crawler $links */
    private function assertRegisterIsTheOnlyLink(iterable $links, string $context): void
    {
        $hrefs = [];
        foreach ($links as $a) {
            $hrefs[] = $a->getAttribute('href');
        }

        self::assertContains('/register', $hrefs, sprintf('"%s" must carry the register CTA', $context));
        self::assertSame(
            [],
            array_values(array_diff(array_unique($hrefs), ['/register'])),
            sprintf('"%s" must link nowhere except /register (CTA-only page)', $context)
        );
    }

    public function testDiabetesPageAvoidsTheConditionWordInEveryLocale(): void
    {
        // Marketing Plan §2: landing-page copy stays aligned with the ad-copy rule —
        // "blood sugar"/"glucose", never the condition name — and i18n Phase 4
        // carries that into every locale variant. The "diabet" stem covers
        // en/es/de/fr; ru and hi have their own words; German additionally has
        // Zuckerkrankheit and Hindi the native मधुमेह. The slug stays 'diabetes'
        // (internal campaign name) — only visible text is bound by the rule.
        $client = static::createClient();
        $banned = ['diabet', 'zuckerkrank', 'диабет', 'डायबिटीज', 'मधुमेह'];

        foreach (['', '/es', '/es-mx', '/es-es', '/en-gb', '/de', '/fr', '/ru', '/hi'] as $prefix) {
            $crawler = $client->request('GET', "{$prefix}/lp/diabetes");
            self::assertResponseIsSuccessful("{$prefix}/lp/diabetes");
            $body = $crawler->filter('body')->text();
            foreach ($banned as $stem) {
                self::assertStringNotContainsStringIgnoringCase(
                    $stem,
                    $body,
                    "{$prefix}/lp/diabetes contains banned stem \"{$stem}\"",
                );
            }
        }

        // The English page still leads with the allowed framing.
        $crawler = $client->request('GET', '/lp/diabetes');
        self::assertStringContainsStringIgnoringCase('blood sugar', $crawler->filter('body')->text());
    }

    public function testWeightLossPageLabelsPaidFeaturesHonestly(): void
    {
        // Comprehensive Review §9, both directions (updated for goal tiering):
        // goal setup is free with the first 6 check-ins included, daily or weekly
        // check-ins by email continue on either paid plan, text delivery is Loop+ —
        // and the page must say exactly that wherever it sells goals: no
        // implying gated features are free, and no implying free ones are paid.
        $client = static::createClient();
        $crawler = $client->request('GET', '/lp/weight-loss');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();

        self::assertStringContainsString('Goal setup is free', $body, 'goal definition must be labeled free');
        self::assertStringContainsString('first 6 check-ins', $body, 'the Free allowance must be disclosed');
        self::assertStringContainsString('Loop ($2/month, your own AI key)', $body, 'Loop must name its key');
        self::assertStringContainsString('Loop+ ($13.50/month, AI included)', $body);
        self::assertStringContainsString('daily or weekly', $body, 'the cadence must be named');
        self::assertStringContainsString('Loop+ also sends them by text', $body, 'texts must name Loop+');
        self::assertStringContainsString('first 10 photo analyses', $body, 'footnote must disclose the Free photo cap');
    }

    /**
     * Every landing page must say what phone NouriLoop needs. The pages carried
     * no platform signal at all until 2026-07-26 (Marketing Plan §7.7) while the
     * CGM story runs through Apple Health — so three of four sat under an
     * implied iOS requirement they never had.
     */
    public function testEveryPageStatesTheProductRunsOnAnyPhone(): void
    {
        $client = static::createClient();

        foreach (array_keys(LandingPageController::PAGES) as $slug) {
            $crawler = $client->request('GET', '/lp/' . $slug);
            self::assertResponseIsSuccessful();
            $text = $crawler->text();
            self::assertStringContainsString('iPhone', $text, "/lp/$slug should name iPhone");
            self::assertStringContainsString('Android', $text, "/lp/$slug should name Android");
        }
    }

    /**
     * ⚠️ The whole point of the sensor note: on Android only Stelo writes
     * glucose to Health Connect — Lingo doesn't share readings at all — so
     * "works with your Lingo or Stelo" silently fails for Android+Lingo
     * visitors arriving on PAID traffic. A caveat rendered after the first
     * register link is one they can miss entirely, which is exactly where it
     * first shipped (Copilot catch on PR #185).
     */
    public function testDiabetesSensorCaveatPrecedesTheFirstRegisterLink(): void
    {
        $client = static::createClient();
        $client->request('GET', '/lp/diabetes');
        self::assertResponseIsSuccessful();

        $html = $client->getResponse()->getContent();
        $caveat = strpos($html, 'Which sensor, which phone?');
        self::assertNotFalse($caveat, 'The diabetes page must carry the sensor caveat.');

        $firstCta = strpos($html, '/register');
        self::assertNotFalse($firstCta, 'The diabetes page must link to registration.');
        self::assertLessThan(
            $firstCta,
            $caveat,
            'The sensor caveat must render BEFORE the first register link, or an '
            . 'Android+Lingo visitor can click through without ever seeing it.',
        );

        // …and it must name both sensors, since the distinction is the content.
        self::assertStringContainsString('Stelo', $html);
        self::assertStringContainsString('Lingo', $html);
    }

    public function testUnknownSlugIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/lp/does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSitemapDoesNotListLandingPages(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/lp/', (string) $client->getResponse()->getContent());
    }
}

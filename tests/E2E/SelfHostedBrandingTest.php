<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Hosting\Brand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Configurable branding (App Development Plan PR 6b; decisions Q5, Q11). NouriLoop's name,
 * logo, company pages, legal documents and analytics belong to NouriLoop LLC: the hosted
 * service keeps them, a self-hosted copy carries its operator's. Env values are read when a
 * kernel builds its services, so each test sets them before creating its client.
 */
final class SelfHostedBrandingTest extends WebTestCase
{
    use DbResetTrait;

    /** public/site.webmanifest as it was before the manifest became a route. */
    private const HOSTED_MANIFEST = [
        'name' => 'NouriLoop',
        'short_name' => 'NouriLoop',
        'description' => 'See what your food does to you.',
        'start_url' => '/',
        'display' => 'standalone',
        'background_color' => '#1f2937',
        'theme_color' => '#1f2937',
        'icons' => [
            [
                'src' => '/icon-192x192.png', 'sizes' => '192x192',
                'type' => 'image/png', 'purpose' => 'any maskable',
            ],
            [
                'src' => '/icon-512x512.png', 'sizes' => '512x512',
                'type' => 'image/png', 'purpose' => 'any maskable',
            ],
        ],
    ];

    /** @var array<string, string|null> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
        $this->saved = [];
        parent::tearDown();
    }

    public function testTheHostedServiceKeepsNouriLoopsIdentity(): void
    {
        $client = $this->signedIn(['APP_HOSTING_MODE' => 'hosted']);

        $crawler = $client->request('GET', '/settings/language');
        self::assertStringEndsWith('— NouriLoop', trim($crawler->filter('title')->text()));
        self::assertSelectorTextContains('.navbar-brand', 'NouriLoop');
        self::assertSelectorExists('.navbar-brand img[src*="navbar-logo.png"]');
        self::assertSelectorTextContains('main, body', 'Choose the language NouriLoop uses with you.');
        self::assertSelectorTextContains('footer', 'NouriLoop LLC');
        self::assertSelectorExists('footer a[href="' . Brand::DEFAULT_SOURCE_URL . '"]');

        // Identical to the static manifest it replaced, maskable icons included (Copilot catch on #246).
        $client->request('GET', '/app.webmanifest');
        self::assertSame(self::HOSTED_MANIFEST, json_decode((string) $client->getResponse()->getContent(), true));

        $client->request('GET', '/.well-known/security.txt');
        $securityTxt = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Contact: mailto:info@nouriloop.com', $securityTxt);
    }

    public function testASelfHostedCopyIsCalledByItsDomainAndCarriesNoNouriLoopBrand(): void
    {
        $client = $this->signedIn([
            'APP_HOSTING_MODE' => 'self',
            'DEFAULT_URI' => 'https://www.food.example.org/',
            // A copied production .env.local must not load NouriLoop's trackers.
            'GOOGLE_ANALYTICS_ID' => 'G-NOURILOOP',
            'META_PIXEL_ID' => '1234567890',
        ]);

        $crawler = $client->request('GET', '/settings/language');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringEndsWith('— food.example.org', trim($crawler->filter('title')->text()));
        self::assertSelectorTextContains('.navbar-brand', 'food.example.org');
        self::assertSelectorNotExists('.navbar-brand img');
        self::assertSelectorTextContains('main, body', 'Choose the language food.example.org uses with you.');
        self::assertSelectorTextContains('footer', 'Runs on NouriLoop, open-source software');
        self::assertSelectorTextNotContains('footer', 'NouriLoop LLC');
        self::assertStringNotContainsString('og-card.png', $html);
        self::assertStringNotContainsString('favicon-32x32.png', $html);
        self::assertStringContainsString('images/neutral-icon.svg', $html);
        self::assertStringNotContainsString('G-NOURILOOP', $html);
        self::assertStringNotContainsString('1234567890', $html);

        $client->request('GET', '/help/log-meal');
        self::assertSelectorTextContains('body', 'Logging a meal is the heart of food.example.org');
        self::assertSelectorTextNotContains('body', 'heart of NouriLoop');

        $client->request('GET', '/app.webmanifest');
        $manifest = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('food.example.org', $manifest['name']);
        self::assertSame('/images/neutral-icon.svg', $manifest['icons'][0]['src']);

        foreach (['/about', '/es/about', '/lp/fiber', '/es/lp/fiber'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        $client->request('GET', '/sitemap.xml');
        self::assertStringNotContainsString('/about', (string) $client->getResponse()->getContent());
    }

    public function testTheOperatorsNameLogoAndForkAreUsedWhenSet(): void
    {
        $client = $this->signedIn([
            'APP_HOSTING_MODE' => 'self',
            'APP_NAME' => 'Family Food Log',
            'APP_LOGO_URL' => 'https://cdn.example.org/family-logo.png',
            'SOURCE_URL' => 'https://git.example.org/family/nouriloop-fork',
        ]);

        $crawler = $client->request('GET', '/settings');
        self::assertStringEndsWith('— Family Food Log', trim($crawler->filter('title')->text()));
        self::assertSelectorTextContains('body', 'Manage your preferences, notifications, security, and account.');
        self::assertSelectorExists('.navbar-brand img[src="https://cdn.example.org/family-logo.png"]');
        self::assertSelectorExists('footer a[href="https://git.example.org/family/nouriloop-fork"]');

        // The home-screen icon follows the same logo rule (Copilot catch on #246).
        $client->request('GET', '/app.webmanifest');
        $manifest = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('https://cdn.example.org/family-logo.png', $manifest['icons'][0]['src']);
    }

    public function testLegalPagesGoToTheOperatorsDocumentsOrSayWhoRunsTheCopy(): void
    {
        $client = $this->anonymous([
            'APP_HOSTING_MODE' => 'self',
            'APP_NAME' => 'Family Food Log',
            'OPERATOR_EMAIL' => 'admin@example.org',
        ]);
        foreach (['/privacy/health', '/privacy/sms', '/privacy/sms-optin', '/privacy/terms'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertSelectorTextContains('body', 'is run by its operator, not by NouriLoop LLC');
            self::assertSelectorExists('a[href="mailto:admin@example.org"]');
            self::assertSelectorTextNotContains('body', 'Portland OR');
        }

        $client = $this->anonymous([
            'APP_HOSTING_MODE' => 'self',
            'PRIVACY_URL' => 'https://example.org/privacy',
            'TERMS_URL' => 'https://example.org/terms',
        ]);
        $client->request('GET', '/privacy/health');
        self::assertResponseRedirects('https://example.org/privacy');
        $client->request('GET', '/privacy/terms');
        self::assertResponseRedirects('https://example.org/terms');
    }

    public function testSecurityTxtNamesTheOperatorOrIsAbsent(): void
    {
        $client = $this->anonymous(['APP_HOSTING_MODE' => 'self']);
        $client->request('GET', '/.well-known/security.txt');
        self::assertResponseStatusCodeSame(404);

        $client = $this->anonymous(['APP_HOSTING_MODE' => 'self', 'OPERATOR_EMAIL' => 'admin@example.org']);
        $client->request('GET', '/.well-known/security.txt');
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Contact: mailto:admin@example.org', $body);
        self::assertStringNotContainsString('info@nouriloop.com', $body);
    }

    public function testRegistrationSendsTheVerificationEmailInsteadOfJohnsLetter(): void
    {
        $client = $this->anonymous(['APP_HOSTING_MODE' => 'self', 'DEFAULT_URI' => 'https://food.example.org']);
        $this->register($client, 'newcomer@example.org');

        $subjects = array_map(
            static fn($m): string => (string) $m->getSubject(),
            self::getMailerMessages(),
        );
        self::assertContains('Confirm your food.example.org email', $subjects);
        self::assertNotContains('Welcome to NouriLoop', $subjects);

        $client = $this->anonymous(['APP_HOSTING_MODE' => 'hosted']);
        $this->register($client, 'hostednewcomer@example.org');
        self::assertContains('Welcome to NouriLoop', array_map(
            static fn($m): string => (string) $m->getSubject(),
            self::getMailerMessages(),
        ));
    }

    private function register(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/register');
        $client->submit($crawler->filter('form[name="registration_form"]')->form([
            'registration_form[displayName]' => 'New Person',
            'registration_form[email]' => $email,
            'registration_form[plainPassword][first]' => 'correct-horse-battery',
            'registration_form[plainPassword][second]' => 'correct-horse-battery',
        ]));
        self::assertResponseRedirects();
    }

    /** @param array<string, string> $env */
    private function anonymous(array $env): KernelBrowser
    {
        foreach ($env as $name => $value) {
            $this->setEnv($name, $value);
        }
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->truncateAll(static::getContainer()->get(EntityManagerInterface::class));

        return $client;
    }

    /** @param array<string, string> $env */
    private function signedIn(array $env): KernelBrowser
    {
        $client = $this->anonymous($env);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('brand@test.local');
        $user->setDisplayName('Brand');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setOnboardingDismissed(true);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        return $client;
    }

    private function setEnv(string $name, string $value): void
    {
        if (!\array_key_exists($name, $this->saved)) {
            $this->saved[$name] = $_ENV[$name] ?? null;
        }
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}

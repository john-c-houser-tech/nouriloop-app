<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * APP_HOSTING_MODE and the instance address (App Development Plan PR 6a). A self-hosted
 * copy has no billing: every feature for every account, no allowance counters, and the
 * pricing / checkout / webhook routes answer 404. The CGM wizard shows the instance's own
 * DEFAULT_URI. Both are %env()% values read when a kernel builds its services, so each
 * test sets them before creating its client and restores them afterwards.
 */
final class SelfHostedModeTest extends WebTestCase
{
    use DbResetTrait;

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

    public function testTheHostedServiceKeepsBilling(): void
    {
        $client = $this->signedIn('hosted');

        $client->request('GET', '/pricing');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/sitemap.xml');
        self::assertStringContainsString('/pricing', (string) $client->getResponse()->getContent());
        $client->request('GET', '/about');
        self::assertSelectorExists('a[href="/pricing"]');
        $client->request('GET', '/settings');
        self::assertSelectorExists('a[href="/settings/subscription"]');
        $client->request('GET', '/meal/log');
        self::assertSelectorTextContains('body', 'free AI analyses left this month');
    }

    public function testASelfHostedInstanceHasNoBillingAndEveryFeature(): void
    {
        $client = $this->signedIn('self');

        foreach (['/pricing', '/es/pricing', '/settings/subscription'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        $client->request('POST', '/webhook/stripe', [], [], [], '{}');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/settings');
        self::assertSelectorNotExists('a[href="/settings/subscription"]');

        // Public pages don't advertise the 404 (Copilot catch on #245).
        $client->request('GET', '/sitemap.xml');
        self::assertStringNotContainsString('/pricing', (string) $client->getResponse()->getContent());
        $client->request('GET', '/about');
        self::assertSelectorNotExists('a[href$="/pricing"]');

        // A Free account on a self-hosted copy: no allowance counter, no Upgrade nudge,
        // and a Loop+ page (glucose insights) opens instead of sending them to pricing.
        $client->request('GET', '/meal/log');
        self::assertSelectorTextNotContains('body', 'free AI analyses left this month');
        self::assertSelectorNotExists('a.nav-link[href="/pricing"]');
        $client->request('GET', '/insights/glucose');
        self::assertResponseIsSuccessful();
    }

    public function testTheCgmWizardShowsThisInstancesAddress(): void
    {
        $client = $this->signedIn('hosted');
        $client->request('GET', '/connect-cgm');
        self::assertSame(
            'https://app.nouriloop.com/api/health-metrics/health-export',
            $client->getCrawler()->filter('#cfg-url')->attr('value'),
        );

        $client = $this->signedIn('self', 'https://nl.example.org/');
        $client->request('GET', '/connect-cgm');
        self::assertSame(
            'https://nl.example.org/api/health-metrics/health-export',
            $client->getCrawler()->filter('#cfg-url')->attr('value'),
            'a trailing slash on DEFAULT_URI is tolerated',
        );
    }

    private function signedIn(string $mode, ?string $baseUri = null): KernelBrowser
    {
        $this->setEnv('APP_HOSTING_MODE', $mode);
        if ($baseUri !== null) {
            $this->setEnv('DEFAULT_URI', $baseUri);
        }
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = new User();
        $user->setEmail('selfhost@test.local');
        $user->setDisplayName('Self Host');
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

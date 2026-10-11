<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Service\ApiTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * First-touch campaign attribution (utm_* / gclid) and the counts endpoint that
 * reads it — the first-party ground truth behind Campaigner's conversion-integrity
 * rules. In-process (no browser) but DB-backed, so it lives in the e2e job.
 */
final class AttributionTest extends WebTestCase
{
    use DbResetTrait;

    private const LP_URL = '/lp/weight-loss?utm_source=google&utm_medium=cpc'
        . '&utm_campaign=google-weight-loss&utm_content=variant-1&gclid=Cj0KTest123';


    public function testLandingPageParamsArePersistedAtRegistration(): void
    {
        $client = $this->freshClient();

        // Arrive from an ad, browse a bit, then register — the capture has to
        // survive the pages in between.
        $client->request('GET', self::LP_URL);
        $this->assertResponseIsSuccessful();
        $client->request('GET', '/about');

        $this->register($client, 'attributed@test.local');

        $user = $this->reload('attributed@test.local');
        self::assertSame('google', $user->getUtmSource());
        self::assertSame('cpc', $user->getUtmMedium());
        self::assertSame('google-weight-loss', $user->getUtmCampaign());
        self::assertSame('variant-1', $user->getUtmContent());
        self::assertSame('Cj0KTest123', $user->getGclid());
    }

    public function testFirstTouchWinsOverALaterCampaign(): void
    {
        $client = $this->freshClient();

        $client->request('GET', self::LP_URL);
        // The same visitor clicks a second ad before signing up: the campaign that
        // FOUND them keeps the credit.
        $client->request('GET', '/lp/fiber?utm_campaign=google-fiber&utm_source=google');

        $this->register($client, 'firsttouch@test.local');

        $user = $this->reload('firsttouch@test.local');
        self::assertSame('google-weight-loss', $user->getUtmCampaign());
        self::assertSame('variant-1', $user->getUtmContent(), 'the whole first-touch set is kept intact');
    }

    public function testDirectRegistrationStoresNoAttribution(): void
    {
        $client = $this->freshClient();

        $this->register($client, 'organic@test.local');

        $user = $this->reload('organic@test.local');
        self::assertNull($user->getUtmCampaign());
        self::assertNull($user->getGclid());
    }

    public function testCapturedValuesAreCleanedAndBounded(): void
    {
        $client = $this->freshClient();

        // Query strings are attacker-controllable and land in a VARCHAR(255) that
        // the counts endpoint echoes back verbatim.
        $client->request('GET', '/lp/fiber?utm_campaign=' . rawurlencode(str_repeat('x', 400) . "\n")
            . '&utm_source=' . rawurlencode("goo\x00gle") . '&gclid=' . rawurlencode('   '));

        $this->register($client, 'bounded@test.local');

        $user = $this->reload('bounded@test.local');
        self::assertSame(str_repeat('x', 255), $user->getUtmCampaign(), 'truncated, control chars stripped');
        self::assertSame('google', $user->getUtmSource());
        self::assertNull($user->getGclid(), 'a whitespace-only param is not stored');
    }

    public function testRegistrationsEndpointGroupsByCampaign(): void
    {
        $client = $this->freshClient();

        // The admin sits outside the window so the buckets below are exactly the
        // seeded registrations (and the test can't drift as the real date moves).
        $this->seedRegistration('metrics-admin@test.local', null, '2026-01-05 08:00:00', admin: true);
        $this->seedRegistration('a@test.local', 'google-fiber', '2026-07-10 09:00:00');
        $this->seedRegistration('b@test.local', 'google-fiber', '2026-07-31 23:30:00');
        $this->seedRegistration('c@test.local', 'google-diabetes', '2026-07-15 12:00:00');
        $this->seedRegistration('d@test.local', null, '2026-07-16 12:00:00');
        // Just outside the window on both sides.
        $this->seedRegistration('e@test.local', 'google-fiber', '2026-06-30 23:59:00');
        $this->seedRegistration('f@test.local', 'google-fiber', '2026-08-01 00:01:00');

        $body = $this->callMetrics($client, 'metrics-admin@test.local', '?from=2026-07-01&to=2026-07-31');

        self::assertSame('2026-07-01', $body['from']);
        self::assertSame('2026-07-31', $body['to']);
        self::assertSame(4, $body['total'], 'a `to`-day registration at 23:30 is inside the window');
        self::assertSame(
            [
                ['utm_campaign' => 'google-fiber', 'count' => 2],
                // Ties break on the campaign name (NULLs first), so the order a
                // repeated poll sees is stable. Unattributed arrivals are their
                // own visible bucket, never dropped.
                ['utm_campaign' => null, 'count' => 1],
                ['utm_campaign' => 'google-diabetes', 'count' => 1],
            ],
            $body['campaigns'],
        );
    }

    public function testRegistrationsEndpointRejectsJunkDatesAndNonAdmins(): void
    {
        $client = $this->freshClient();

        $this->seedRegistration('metrics-admin@test.local', null, '2026-01-05 08:00:00', admin: true);
        $this->seedRegistration('plain@test.local', null, '2026-01-06 08:00:00');

        $this->callMetrics($client, 'metrics-admin@test.local', '?from=last-tuesday', expectedStatus: 400);
        $this->callMetrics($client, 'metrics-admin@test.local', '?from=2026-02-30', expectedStatus: 400);
        $this->callMetrics(
            $client,
            'metrics-admin@test.local',
            '?from=2026-07-31&to=2026-07-01',
            expectedStatus: 400,
        );

        // Business-wide figures: an ordinary user's bearer token must not read them.
        $this->callMetrics($client, 'plain@test.local', '', expectedStatus: 403);

        // And no token at all is a 401 from the stateless firewall.
        $client->request('GET', '/api/metrics/registrations');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    /**
     * The container's EntityManager, re-fetched every time: KernelBrowser reboots
     * the kernel between requests, so a held reference goes stale and entities
     * loaded through it end up detached from the EM a service is using.
     */
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testApiRequestsNeverStartASession(): void
    {
        $client = $this->freshClient();
        $this->seedRegistration('metrics-admin@test.local', null, '2026-01-05 08:00:00', admin: true);

        // A GET route under the stateless ^/api firewall (this feature added the
        // first one), carrying a tracked parameter. Capture must not fire: a
        // bearer-token client must not be handed a session cookie.
        $this->callMetrics($client, 'metrics-admin@test.local', '?utm_campaign=google-fiber');

        $response = $client->getResponse();
        self::assertSame(
            [],
            $response->headers->getCookies(),
            'no Set-Cookie on a stateless API response',
        );
        self::assertFalse(
            $client->getRequest()->getSession()->isStarted(),
            'the API request did not start a session',
        );
    }

    private function freshClient(): KernelBrowser
    {
        $client = static::createClient();
        $this->truncateAll($this->em());

        return $client;
    }

    /**
     * Calls the endpoint with a freshly minted bearer token for $email.
     *
     * @return array<string, mixed> the decoded body
     */
    private function callMetrics(
        KernelBrowser $client,
        string $email,
        string $query,
        int $expectedStatus = 200,
    ): array {
        $tokens = static::getContainer()->get(ApiTokenManager::class);
        [, $plaintext] = $tokens->create($this->reload($email), 'Campaigner');

        $client->request(
            'GET',
            '/api/metrics/registrations' . $query,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $plaintext],
        );

        self::assertSame($expectedStatus, $client->getResponse()->getStatusCode());

        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function register(KernelBrowser $client, string $email): void
    {
        $client->request('GET', '/register');
        $client->submitForm('Create Account', [
            'registration_form[displayName]' => 'Ad Clicker',
            'registration_form[email]' => $email,
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
        ]);
        $this->assertResponseRedirects('/');
    }

    private function reload(string $email): User
    {
        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user, sprintf('user %s exists', $email));

        return $user;
    }

    /** A user who registered at a fixed instant with a given first-touch campaign. */
    private function seedRegistration(
        string $email,
        ?string $campaign,
        string $createdAt,
        bool $admin = false,
    ): void {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Seeded');
        $user->setRoles($admin ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $user->setUtmCampaign($campaign);

        $this->em()->persist($user);
        $this->em()->flush();

        // createdAt is a constructor-set audit value with no setter — backdate it
        // in SQL so the window boundaries can be exercised.
        $this->em()->getConnection()->executeStatement(
            'UPDATE `user` SET created_at = :created WHERE id = :id',
            ['created' => $createdAt, 'id' => $user->getId()],
        );
        $this->em()->clear();
    }
}

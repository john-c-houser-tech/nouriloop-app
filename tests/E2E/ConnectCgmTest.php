<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\ApiToken;
use App\Entity\HealthMetric;
use App\Entity\User;
use App\Enum\HealthMetricType;
use App\Service\ApiTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The "Connect your Lingo/Stelo" guided setup, end-to-end against the real DB
 * (in-process WebTestCase — kept in the e2e job for the DB, no browser): the
 * wizard renders, token creation shows the plaintext exactly once (and weaves
 * it into the step-3 config values), and the /status verification ladder walks
 * no_token → token_unused → waiting → sparse → dense, with "dense" marking the
 * onboarding health step. Fully deterministic — no Claude involved anywhere.
 */
final class ConnectCgmTest extends WebTestCase
{
    use DbResetTrait;

    private const TZ = 'America/New_York';

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $client->request('GET', '/connect-cgm');
        self::assertResponseRedirects('/login');
    }

    public function testWizardRendersAllStepsForFreshUser(): void
    {
        [$client, , ] = $this->freshClient('render@test.local');

        $crawler = $client->request('GET', '/connect-cgm');
        self::assertResponseIsSuccessful();

        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Connect Your Lingo or Stelo', $body);
        self::assertStringContainsString('Install "Health Auto Export"', $body);
        self::assertStringContainsString('Create your NouriLoop access token', $body);
        self::assertStringContainsString('Set up the automation', $body);
        self::assertStringContainsString('Send your first export', $body);
        self::assertStringContainsString('Watch it arrive', $body);
        // No token yet: the create button leads, and step 3's bearer value points back.
        self::assertStringContainsString('Create my token', $body);
        self::assertStringContainsString('do Step 2 first', $body);
        // The Summarize Data warning — the single most important instruction.
        self::assertStringContainsString('Summarize Data', $body);
        self::assertStringContainsString('daily summaries', $body);
    }

    public function testTokenCreationShowsPlaintextOnceAndFillsStepThree(): void
    {
        [$client, $em, ] = $this->freshClient('token@test.local');

        $crawler = $client->request('GET', '/connect-cgm');
        $client->submit($crawler->filter('form[action$="/connect-cgm/token"]')->form());
        self::assertResponseRedirects('/connect-cgm#step-token');

        $crawler = $client->followRedirect();
        $plaintext = $crawler->filter('#new-token')->attr('value');
        self::assertStringStartsWith('nutri_', (string) $plaintext);
        // Step 3's header value is prefilled from the same one-shot flash.
        self::assertSame('Bearer ' . $plaintext, $crawler->filter('#cfg-bearer')->attr('value'));

        $token = $em->getRepository(ApiToken::class)->findOneBy(['name' => 'Health Auto Export']);
        self::assertNotNull($token);

        // Reload: the plaintext is gone for good, replaced by the "fresh one" hint.
        $crawler = $client->request('GET', '/connect-cgm');
        self::assertCount(0, $crawler->filter('#new-token'));
        self::assertStringContainsString('just create a fresh one', $crawler->filter('body')->text());
    }

    public function testStatusLadderTokenRungs(): void
    {
        [$client, $em, $user] = $this->freshClient('rungs@test.local');

        self::assertSame('no_token', $this->fetchStatus($client)['state']);

        $token = $this->seedToken($em, $user, null);
        self::assertSame('token_unused', $this->fetchStatus($client)['state']);

        // Kernel reboots between requests detach $token, so a flush() would be
        // silently lost — stamp last_used_at with direct SQL instead.
        $stamp = fn (string $modify) => $em->getConnection()->executeStatement(
            'UPDATE api_token SET last_used_at = ? WHERE id = ?',
            [(new \DateTimeImmutable($modify))->format('Y-m-d H:i:s'), $token->getId()],
        );

        // A stale use (outside the 48 h window) doesn't count as "phone reached".
        $stamp('-3 days');
        self::assertSame('token_unused', $this->fetchStatus($client)['state']);

        // A fresh use does — now we're waiting on glucose.
        $stamp('-2 minutes');
        $status = $this->fetchStatus($client);
        self::assertSame('waiting', $status['state']);
        self::assertNotNull($status['phoneReachedAt']);
        self::assertSame(0, $status['glucose']['recentCount']);
    }

    public function testOldGlucoseCannotFakeSuccess(): void
    {
        [$client, $em, $user] = $this->freshClient('stale@test.local');
        $this->seedToken($em, $user, new \DateTimeImmutable('-2 minutes'));

        $base = $this->userNow()->modify('-30 days');
        $this->seedGlucose($em, $user, [$base, $base->modify('+5 minutes'), $base->modify('+10 minutes')]);

        self::assertSame('waiting', $this->fetchStatus($client)['state']);
    }

    public function testDayAggregatedGlucoseIsSparse(): void
    {
        [$client, $em, $user] = $this->freshClient('sparse@test.local');

        // The classic misconfiguration: one collapsed reading per day.
        $now = $this->userNow();
        $this->seedGlucose($em, $user, [$now->modify('-1 hour'), $now->modify('-25 hours')]);

        $status = $this->fetchStatus($client);
        self::assertSame('sparse', $status['state']);
        self::assertSame(2, $status['glucose']['recentCount']);
    }

    public function testHourlyCadenceIsStillTooSparseForCurves(): void
    {
        [$client, $em, $user] = $this->freshClient('hourly@test.local');

        $now = $this->userNow();
        $readings = [];
        for ($i = 0; $i < 25; $i++) {
            $readings[] = $now->modify(sprintf('-%d minutes', 60 * $i));
        }
        $this->seedGlucose($em, $user, $readings);

        $status = $this->fetchStatus($client);
        self::assertSame('sparse', $status['state']);
        self::assertSame(60, $status['glucose']['medianGapMinutes']);
    }

    public function testDenseCadenceSucceedsAndMarksOnboardingHealthStep(): void
    {
        [$client, $em, $user] = $this->freshClient('dense@test.local');
        self::assertNull($user->getOnboardingHealthAt());

        $now = $this->userNow();
        $readings = [];
        for ($i = 0; $i < 30; $i++) {
            $readings[] = $now->modify(sprintf('-%d minutes', 5 * $i));
        }
        $this->seedGlucose($em, $user, $readings);

        $status = $this->fetchStatus($client);
        self::assertSame('dense', $status['state']);
        self::assertSame(30, $status['glucose']['recentCount']);
        self::assertSame(5, $status['glucose']['medianGapMinutes']);

        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'dense@test.local']);
        $markedAt = $reloaded->getOnboardingHealthAt();
        self::assertNotNull($markedAt, 'reaching dense marks the onboarding health step');

        // Idempotent on the next poll.
        self::assertSame('dense', $this->fetchStatus($client)['state']);
        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'dense@test.local']);
        self::assertEquals($markedAt, $reloaded->getOnboardingHealthAt());
    }

    public function testDenseGlucoseWithoutAnyTokenStillSucceeds(): void
    {
        // A file-upload user has no token at all — glucose evidence trumps the rungs.
        [$client, $em, $user] = $this->freshClient('upload@test.local');

        $now = $this->userNow();
        $readings = [];
        for ($i = 0; $i < 15; $i++) {
            $readings[] = $now->modify(sprintf('-%d minutes', 5 * $i));
        }
        $this->seedGlucose($em, $user, $readings);

        $status = $this->fetchStatus($client);
        self::assertSame('dense', $status['state']);
        self::assertFalse($status['tokenCreated']);
    }

    /**
     * @return array{0: KernelBrowser, 1: EntityManagerInterface, 2: User}
     */
    private function freshClient(string $email): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, $email);
        $client->loginUser($user);

        return [$client, $em, $user];
    }

    /**
     * @return array<string, mixed> decoded /connect-cgm/status JSON
     */
    private function fetchStatus(KernelBrowser $client): array
    {
        $client->request('GET', '/connect-cgm/status');
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    /**
     * "Now" in the seeded user's wall-clock frame — glucose recorded_at is stored
     * as user-local wall clock, so readings must be seeded in the same frame.
     */
    private function userNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
    }

    private function seedToken(EntityManagerInterface $em, User $user, ?\DateTimeImmutable $lastUsedAt): ApiToken
    {
        $token = new ApiToken();
        $token->setUser($user);
        $token->setName('Health Auto Export');
        $token->setTokenHash(ApiTokenManager::hash(ApiTokenManager::generatePlaintext()));
        $token->setLastUsedAt($lastUsedAt);
        $em->persist($token);
        $em->flush();

        return $token;
    }

    /**
     * @param list<\DateTimeImmutable> $recordedAts
     */
    private function seedGlucose(EntityManagerInterface $em, User $user, array $recordedAts): void
    {
        foreach ($recordedAts as $at) {
            $metric = new HealthMetric();
            $metric->setUser($user);
            $metric->setMetricType(HealthMetricType::BloodGlucose);
            $metric->setValue(100.0);
            $metric->setRecordedAt($at);
            $metric->setSource('test');
            $em->persist($metric);
        }
        $em->flush();
    }

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Seeded User');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone(self::TZ);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}

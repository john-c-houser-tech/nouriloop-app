<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `app:grant-comp`, end-to-end against the real DB (in-process, not Panther —
 * same rationale as PurgeDeletedAccountsCommandTest/BetaGrandfatheringTest).
 */
final class GrantCompCommandTest extends WebTestCase
{
    use DbResetTrait;

    public function testDefaultGrantIsPermanentLoopPlus(): void
    {
        $em = $this->bootAndReset();
        $this->seedUser($em, 'tester@test.local');

        $tester = $this->runCmd(['email' => 'tester@test.local']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Loop+', $tester->getDisplay());

        $user = $this->reload($em, 'tester@test.local');
        self::assertSame(SubscriptionTier::LoopPlus, $user->getCompTier());
        self::assertNull($user->getCompExpiresAt());
    }

    public function testTierAndExpiresOptionsAreApplied(): void
    {
        $em = $this->bootAndReset();
        $this->seedUser($em, 'tester@test.local');

        $tester = $this->runCmd([
            'email' => 'tester@test.local',
            '--tier' => 'loop',
            '--expires' => '2030-01-01',
        ]);
        $tester->assertCommandIsSuccessful();

        $user = $this->reload($em, 'tester@test.local');
        self::assertSame(SubscriptionTier::Loop, $user->getCompTier());
        self::assertSame('2030-01-01', $user->getCompExpiresAt()?->format('Y-m-d'));
    }

    public function testRevokeClearsComp(): void
    {
        $em = $this->bootAndReset();
        $user = $this->seedUser($em, 'tester@test.local');
        $user->setCompTier(SubscriptionTier::LoopPlus);
        $em->flush();

        $tester = $this->runCmd(['email' => 'tester@test.local', '--revoke' => true]);
        $tester->assertCommandIsSuccessful();

        $user = $this->reload($em, 'tester@test.local');
        self::assertNull($user->getCompTier());
        self::assertNull($user->getCompExpiresAt());
    }

    public function testDryRunWritesNothing(): void
    {
        $em = $this->bootAndReset();
        $this->seedUser($em, 'tester@test.local');

        $tester = $this->runCmd(['email' => 'tester@test.local', '--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Would grant', $tester->getDisplay());
        self::assertStringNotContainsString('Granted complimentary', $tester->getDisplay());

        // Not even the managed entity is touched (before any clear/reload), so a
        // later flush in the same process couldn't write the "dry" grant.
        $managed = $em->getRepository(User::class)->findOneBy(['email' => 'tester@test.local']);
        self::assertNull($managed?->getCompTier());

        $user = $this->reload($em, 'tester@test.local');
        self::assertNull($user->getCompTier());
    }

    public function testDryRunRevokeReportsWithoutClearing(): void
    {
        $em = $this->bootAndReset();
        $this->seedUser($em, 'tester@test.local');
        $this->runCmd(['email' => 'tester@test.local'])->assertCommandIsSuccessful();

        $tester = $this->runCmd(['email' => 'tester@test.local', '--revoke' => true, '--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Would clear the comp', $tester->getDisplay());

        $managed = $em->getRepository(User::class)->findOneBy(['email' => 'tester@test.local']);
        self::assertNotNull($managed?->getCompTier());
    }

    public function testUnknownEmailFails(): void
    {
        $this->bootAndReset();

        $tester = $this->runCmd(['email' => 'nobody@test.local']);
        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No user found', $tester->getDisplay());
    }

    public function testBlankAndImpossibleExpiresAreRejected(): void
    {
        $em = $this->bootAndReset();
        $this->seedUser($em, 'tester@test.local');

        // Blank would parse as "now" — an instantly lapsed comp.
        $tester = $this->runCmd(['email' => 'tester@test.local', '--expires' => '  ']);
        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('blank', $tester->getDisplay());

        // PHP would silently normalize this to 2027-03-02.
        $tester = $this->runCmd(['email' => 'tester@test.local', '--expires' => '2027-02-30']);
        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('not a real calendar date', $tester->getDisplay());

        $user = $this->reload($em, 'tester@test.local');
        self::assertNull($user->getCompTier());
    }

    public function testInvalidTierFails(): void
    {
        $em = $this->bootAndReset();
        $this->seedUser($em, 'tester@test.local');

        $tester = $this->runCmd(['email' => 'tester@test.local', '--tier' => 'free']);
        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Invalid --tier', $tester->getDisplay());

        $user = $this->reload($em, 'tester@test.local');
        self::assertNull($user->getCompTier());
    }

    private function bootAndReset(): EntityManagerInterface
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        return $em;
    }

    /** @param array<string, mixed> $args */
    private function runCmd(array $args): CommandTester
    {
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('app:grant-comp')
        );
        $tester->execute($args);

        return $tester;
    }

    private function reload(EntityManagerInterface $em, string $email): User
    {
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return $user;
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
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}

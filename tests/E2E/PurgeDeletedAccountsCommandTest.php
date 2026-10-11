<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Command\PurgeDeletedAccountsCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AccountDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `app:purge-deleted-accounts`, end-to-end against the real DB (in-process, not
 * Panther — same rationale as FixSleepOutliersTest/BetaGrandfatheringTest).
 */
final class PurgeDeletedAccountsCommandTest extends WebTestCase
{
    use DbResetTrait;

    public function testDryRunListsWithoutDeleting(): void
    {
        $kernel = self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $due = $this->seedUser($em, 'due@test.local');
        $due->setDeletionRequestedAt(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $em->flush();

        $tester = $this->runCmd(['--dry-run' => true, '--now' => '2026-07-01 00:00:00']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertStringContainsString('due@test.local', $tester->getDisplay());

        $em->clear();
        self::assertNotNull($em->getRepository(User::class)->findOneBy(['email' => 'due@test.local']));
    }

    public function testApplyPurgesOnlyAccountsPastTheGracePeriod(): void
    {
        $kernel = self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $now = new \DateTimeImmutable('2026-07-01 00:00:00');
        $graceDays = AccountDeletionService::GRACE_DAYS;

        // Requested well over GRACE_DAYS ago — due for purge.
        $due = $this->seedUser($em, 'due@test.local');
        $due->setDeletionRequestedAt($now->modify(sprintf('-%d days', $graceDays + 5)));

        // Requested only yesterday — still within the grace period.
        $notYetDue = $this->seedUser($em, 'not-yet-due@test.local');
        $notYetDue->setDeletionRequestedAt($now->modify('-1 day'));

        // No pending deletion at all.
        $untouched = $this->seedUser($em, 'untouched@test.local');
        $em->flush();
        unset($untouched);

        $tester = $this->runCmd(['--now' => $now->format('Y-m-d H:i:s')]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Purged 1 of 1', $tester->getDisplay());

        $em->clear();
        self::assertNull($em->getRepository(User::class)->findOneBy(['email' => 'due@test.local']));
        self::assertNotNull($em->getRepository(User::class)->findOneBy(['email' => 'not-yet-due@test.local']));
        self::assertNotNull($em->getRepository(User::class)->findOneBy(['email' => 'untouched@test.local']));
    }

    public function testNoAccountsDueReportsSuccessWithoutError(): void
    {
        $kernel = self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $this->seedUser($em, 'plain@test.local');

        $tester = $this->runCmd([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('No accounts are due', $tester->getDisplay());
    }

    /**
     * A cancel that lands after selection (here: while the batch's first purge runs)
     * must spare the account — the command re-reads the row instead of trusting the
     * entity it selected.
     */
    public function testCancelDuringTheRunSparesTheAccount(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $first = $this->seedUser($em, 'first@test.local');
        $first->setDeletionRequestedAt(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $canceler = $this->seedUser($em, 'canceler@test.local');
        $canceler->setDeletionRequestedAt(new \DateTimeImmutable('2026-01-02 00:00:00'));
        $em->flush();
        $cancelerId = $canceler->getId();

        $purgedEmails = [];
        $deletion = $this->createStub(AccountDeletionService::class);
        $deletion->method('purgeUser')->willReturnCallback(
            static function (User $user) use ($em, $cancelerId, &$purgedEmails): void {
                $purgedEmails[] = $user->getEmail();
                $em->getConnection()->executeStatement(
                    'UPDATE `user` SET deletion_requested_at = NULL WHERE id = ?',
                    [$cancelerId],
                );
            },
        );

        $tester = $this->runCmdWith($deletion, ['--now' => '2026-07-01 00:00:00']);
        $tester->assertCommandIsSuccessful();
        self::assertSame(['first@test.local'], $purgedEmails);
        self::assertStringContainsString('Skipped canceler@test.local', $tester->getDisplay());
    }

    /** A failed purge fails the run (so cron notices) but still processes the rest. */
    public function testFailedPurgeFailsTheRunAfterFinishingTheBatch(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $this->seedUser($em, 'broken@test.local')
            ->setDeletionRequestedAt(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $this->seedUser($em, 'fine@test.local')
            ->setDeletionRequestedAt(new \DateTimeImmutable('2026-01-02 00:00:00'));
        $em->flush();

        $purgedEmails = [];
        $deletion = $this->createStub(AccountDeletionService::class);
        $deletion->method('purgeUser')->willReturnCallback(
            static function (User $user) use (&$purgedEmails): void {
                if ($user->getEmail() === 'broken@test.local') {
                    throw new \RuntimeException('FK violation');
                }
                $purgedEmails[] = $user->getEmail();
            },
        );

        $tester = $this->runCmdWith($deletion, ['--now' => '2026-07-01 00:00:00']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(['fine@test.local'], $purgedEmails);
        self::assertStringContainsString('Failed to purge broken@test.local', $tester->getDisplay());
        self::assertStringContainsString('1 failed', $tester->getDisplay());
    }

    /** @param array<string, mixed> $opts */
    private function runCmdWith(AccountDeletionService $deletion, array $opts): CommandTester
    {
        $container = static::getContainer();
        $tester = new CommandTester(new PurgeDeletedAccountsCommand(
            $container->get(UserRepository::class),
            $deletion,
            $container->get(EntityManagerInterface::class),
        ));
        $tester->execute($opts);

        return $tester;
    }

    /** @param array<string, mixed> $opts */
    private function runCmd(array $opts): CommandTester
    {
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('app:purge-deleted-accounts')
        );
        $tester->execute($opts);

        return $tester;
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

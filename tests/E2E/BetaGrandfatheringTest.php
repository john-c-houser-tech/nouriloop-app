<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use App\Service\InvitationCodeManager;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Beta-tester grandfathering, end-to-end against the real DB (in-process, not
 * Panther — so it lives here for the DB but excludes from the DB-less unit job):
 * the one-time grandfather command, and the beta-tester invitation-code grant on
 * registration.
 */
final class BetaGrandfatheringTest extends WebTestCase
{
    use DbResetTrait;

    public function testGrandfatherCommandGrantsPermanentLoopPlusAndSkipsExistingComps(): void
    {
        $kernel = self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $plain = $this->seedUser($em, 'a@test.local');
        $alreadyComped = $this->seedUser($em, 'b@test.local');
        $alreadyComped->setCompTier(SubscriptionTier::Loop)
            ->setCompExpiresAt(new \DateTimeImmutable('2026-12-31 00:00:00'));
        $em->flush();

        $tester = new CommandTester(
            (new Application($kernel))->find('app:grandfather-beta-testers')
        );
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $em->clear();
        $reloadedPlain = $em->getRepository(User::class)->findOneBy(['email' => 'a@test.local']);
        $reloadedComped = $em->getRepository(User::class)->findOneBy(['email' => 'b@test.local']);

        // Plain user → PERMANENT Loop+ (no expiry). ⚠️ This is the assertion that
        // keeps the pre-launch cohort separate from BETA_COMP_DURATION: they were
        // promised a permanent comp, and shortening the comp for NEW code
        // redemptions must never start expiring theirs.
        self::assertSame(SubscriptionTier::LoopPlus, $reloadedPlain->getCompTier());
        self::assertNull($reloadedPlain->getCompExpiresAt());

        // Pre-existing comp left untouched (idempotent, won't clobber a beta invite).
        self::assertSame(SubscriptionTier::Loop, $reloadedComped->getCompTier());
        self::assertEquals(
            new \DateTimeImmutable('2026-12-31 00:00:00'),
            $reloadedComped->getCompExpiresAt(),
        );
    }

    public function testDryRunGrandfatherWritesNothing(): void
    {
        $kernel = self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $this->seedUser($em, 'a@test.local');

        $tester = new CommandTester(
            (new Application($kernel))->find('app:grandfather-beta-testers')
        );
        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'a@test.local']);
        self::assertNull($reloaded->getCompTier());
    }

    public function testBetaTesterInviteGrantsATimeLimitedLoopPlusOnRegistration(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $admin = $this->seedUser($em, 'admin@test.local');
        $code = static::getContainer()->get(InvitationCodeManager::class)->create($admin, true);
        $em->flush();

        $this->register($client, 'beta@test.local', $code->getCode());

        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'beta@test.local']);
        self::assertNotNull($user);
        self::assertSame(SubscriptionTier::LoopPlus, $user->getCompTier());

        // Time-limited, and SHORT — a beta comp long enough to outlast the
        // beta makes the tester structurally unable to convert, which is what
        // made "zero paying users" uninformative (Marketing Plan §7.7). Asserted
        // against the constant so the two can't drift, plus an absolute upper
        // bound: if someone lengthens this back toward a year, the signal is
        // gone again and this test should say so.
        $expires = $user->getCompExpiresAt();
        self::assertNotNull($expires);
        $now = new \DateTimeImmutable();
        // Compare instants, not diff()->days — the grant happened a moment
        // before this line, so a day count truncates to 44 and the assertion
        // becomes an off-by-one trap for whoever next changes the duration.
        $expected = $now->modify(SubscriptionService::BETA_COMP_DURATION);
        self::assertLessThan(
            120,
            abs($expected->getTimestamp() - $expires->getTimestamp()),
            'The granted expiry should track BETA_COMP_DURATION.',
        );
        self::assertLessThanOrEqual(
            90,
            (int) $now->diff($expires)->days,
            'A beta comp longer than ~90 days stops being a willingness-to-pay signal.',
        );
    }


    public function testPlainInviteGrantsNoComp(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $admin = $this->seedUser($em, 'admin@test.local');
        $code = static::getContainer()->get(InvitationCodeManager::class)->create($admin, false);
        $em->flush();

        $this->register($client, 'plain@test.local', $code->getCode());

        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'plain@test.local']);
        self::assertNotNull($user);
        self::assertNull($user->getCompTier());
    }

    private function register(object $client, string $email, string $code): void
    {
        $client->request('GET', '/register');
        $client->submitForm('Create Account', [
            'registration_form[displayName]' => 'Beta Person',
            'registration_form[email]' => $email,
            'registration_form[invitationCode]' => $code,
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
        ]);
        $this->assertResponseRedirects('/');
    }

    public function testAdminCanMintBetaTesterCodeViaForm(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $admin = $this->seedUser($em, 'admin@test.local', ['ROLE_USER', 'ROLE_ADMIN']);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');
        $form = $crawler->selectButton('Generate code')->form();
        $form['beta_tester']->tick();
        $client->submit($form);

        $this->assertResponseRedirects('/admin/users');

        $em->clear();
        $codes = $em->getRepository(\App\Entity\InvitationCode::class)->findAll();
        self::assertCount(1, $codes);
        self::assertTrue($codes[0]->isBetaTester());

        // The codes list renders the beta badge (exercises the `c.betaTester` Twig
        // access on a real beta code) AND the promised term. ⚠️ Asserted against
        // BETA_COMP_LABEL rather than a literal: an admin reads this badge when
        // telling a tester what the code is worth, so it drifting away from what
        // grantBetaTesterComp actually grants is the failure mode — it shipped
        // saying "1yr" while the grant was 45 days (Copilot catch on PR #184).
        $crawler = $client->followRedirect();
        self::assertStringContainsString(
            'Beta · ' . SubscriptionService::BETA_COMP_LABEL . ' Loop+',
            $crawler->html(),
        );

        // …and the mint flash makes the same promise, from the same constant.
        self::assertStringContainsString(
            SubscriptionService::BETA_COMP_LABEL . ' free Loop+',
            $crawler->html(),
        );
    }

    private function seedUser(EntityManagerInterface $em, string $email, array $roles = ['ROLE_USER']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Seeded User');
        $user->setRoles($roles);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}

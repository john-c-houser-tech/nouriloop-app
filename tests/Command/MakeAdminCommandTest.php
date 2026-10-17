<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MakeAdminCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Granting admin also verifies the email, so a self-hosted copy with no mailer can
 * clear the verification banner; revoking leaves verification alone.
 */
final class MakeAdminCommandTest extends TestCase
{
    public function testGrantMakesAdminAndVerifiesEmail(): void
    {
        $user = (new User())->setEmail('admin@test.local');

        $tester = $this->tester($user);
        $tester->execute(['email' => 'admin@test.local']);

        $tester->assertCommandIsSuccessful();
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertTrue($user->isEmailVerified());
        self::assertStringContainsString('marked as verified', $tester->getDisplay());
    }

    public function testGrantKeepsAnExistingVerificationDate(): void
    {
        $verifiedAt = new \DateTimeImmutable('2026-01-02 03:04:05');
        $user = (new User())->setEmail('admin@test.local')->setEmailVerifiedAt($verifiedAt);

        $tester = $this->tester($user);
        $tester->execute(['email' => 'admin@test.local']);

        self::assertSame($verifiedAt, $user->getEmailVerifiedAt());
        self::assertStringNotContainsString('marked as verified', $tester->getDisplay());
    }

    public function testRevokeLeavesVerificationAlone(): void
    {
        $user = (new User())->setEmail('admin@test.local')->setRoles(['ROLE_ADMIN']);

        $tester = $this->tester($user);
        $tester->execute(['email' => 'admin@test.local', '--revoke' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertNotContains('ROLE_ADMIN', $user->getRoles());
        self::assertFalse($user->isEmailVerified());
    }

    private function tester(User $user): CommandTester
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneBy')->willReturn($user);
        $em = $this->createStub(EntityManagerInterface::class);

        return new CommandTester(new MakeAdminCommand($users, $em, new EmailVerifier($em)));
    }
}

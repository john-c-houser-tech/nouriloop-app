<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\InvitationCode;
use App\Entity\User;
use App\Service\InvitationCodeManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class InvitationCodeManagerTest extends TestCase
{
    public function testGenerateCodeIs32HexCharsAndUnique(): void
    {
        $a = InvitationCodeManager::generateCode();
        $b = InvitationCodeManager::generateCode();

        self::assertSame(32, strlen($a));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a);
        self::assertNotSame($a, $b);
    }

    public function testCreateMintsCodeWith48HourExpiryAndCreator(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(InvitationCode::class));
        $em->expects(self::once())->method('flush');

        $admin = new User();
        $code  = (new InvitationCodeManager($em))->create($admin);

        self::assertSame(32, strlen($code->getCode()));
        self::assertSame($admin, $code->getCreatedBy());
        self::assertNull($code->getUsedBy());
        self::assertSame(
            InvitationCodeManager::TTL_HOURS * 3600,
            $code->getExpiresAt()->getTimestamp() - $code->getCreatedAt()->getTimestamp(),
        );
    }

    public function testRedeemMarksUsedWithoutFlushing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $code = new InvitationCode();
        $user = new User();
        $now  = new \DateTimeImmutable('2026-06-12 10:00:00');

        (new InvitationCodeManager($em))->redeem($code, $user, $now);

        self::assertSame($user, $code->getUsedBy());
        self::assertSame($now, $code->getUsedAt());
        self::assertTrue($code->isUsed());
    }

    public function testIsActiveReflectsUsageAndExpiry(): void
    {
        $now  = new \DateTimeImmutable('2026-06-12 10:00:00');
        $code = new InvitationCode();
        $code->setExpiresAt($now->modify('+1 hour'));

        self::assertTrue($code->isActive($now));
        self::assertFalse($code->isExpired($now));

        // Expired.
        self::assertFalse($code->isActive($now->modify('+2 hours')));
        self::assertTrue($code->isExpired($now->modify('+2 hours')));

        // Used codes are never active.
        $code->setUsedBy(new User());
        self::assertFalse($code->isActive($now));
    }
}

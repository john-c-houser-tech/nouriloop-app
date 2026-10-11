<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Service\ApiTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ApiTokenManagerTest extends TestCase
{
    public function testHashIsDeterministicSha256(): void
    {
        self::assertSame(hash('sha256', 'nutri_abc'), ApiTokenManager::hash('nutri_abc'));
        self::assertSame(64, strlen(ApiTokenManager::hash('anything')));
    }

    public function testGeneratePlaintextIsPrefixedAndUnique(): void
    {
        $a = ApiTokenManager::generatePlaintext();
        $b = ApiTokenManager::generatePlaintext();

        self::assertStringStartsWith('nutri_', $a);
        self::assertSame(6 + 64, strlen($a)); // prefix + 32 random bytes as hex
        self::assertNotSame($a, $b);
    }

    public function testCreateStoresHashNotPlaintextAndReturnsPlaintextOnce(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(ApiToken::class));
        $em->expects(self::once())->method('flush');

        [$token, $plaintext] = (new ApiTokenManager($em))->create(new User(), 'iPhone');

        self::assertSame('iPhone', $token->getName());
        self::assertNotSame($plaintext, $token->getTokenHash());
        self::assertSame(ApiTokenManager::hash($plaintext), $token->getTokenHash());
    }

    public function testCreateFallsBackToDefaultName(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);

        [$token] = (new ApiTokenManager($em))->create(new User(), '   ');

        self::assertSame('API token', $token->getName());
    }

    public function testTouchSkipsWriteWithinAMinute(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $token = (new ApiToken())->setLastUsedAt(new \DateTimeImmutable('2026-06-10 12:00:00'));
        (new ApiTokenManager($em))->touch($token, new \DateTimeImmutable('2026-06-10 12:00:30'));
    }

    public function testTouchWritesWhenStale(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $token = (new ApiToken())->setLastUsedAt(new \DateTimeImmutable('2026-06-10 12:00:00'));
        $now   = new \DateTimeImmutable('2026-06-10 12:05:00');
        (new ApiTokenManager($em))->touch($token, $now);

        self::assertSame($now, $token->getLastUsedAt());
    }
}

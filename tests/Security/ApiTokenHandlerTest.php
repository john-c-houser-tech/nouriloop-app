<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Security\ApiTokenHandler;
use App\Service\ApiTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class ApiTokenHandlerTest extends TestCase
{
    private function manager(): ApiTokenManager
    {
        // touch() only flushes; a stubbed EM makes that a no-op.
        return new ApiTokenManager($this->createStub(EntityManagerInterface::class));
    }

    public function testResolvesTokenToItsUser(): void
    {
        $user  = (new User())->setEmail('device@example.com');
        $token = (new ApiToken())->setUser($user);

        $repo = $this->createMock(ApiTokenRepository::class);
        $repo->expects(self::once())
            ->method('findOneByHash')
            ->with(ApiTokenManager::hash('nutri_secret'))
            ->willReturn($token);

        $badge = (new ApiTokenHandler($repo, $this->manager()))->getUserBadgeFrom('nutri_secret');

        self::assertSame('device@example.com', $badge->getUserIdentifier());
        self::assertSame($user, ($badge->getUserLoader())());
    }

    public function testRejectsUnknownToken(): void
    {
        $repo = $this->createMock(ApiTokenRepository::class);
        $repo->expects(self::once())->method('findOneByHash')->willReturn(null);

        $this->expectException(BadCredentialsException::class);

        (new ApiTokenHandler($repo, $this->manager()))->getUserBadgeFrom('nope');
    }
}

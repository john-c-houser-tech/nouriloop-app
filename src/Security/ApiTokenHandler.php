<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\ApiTokenRepository;
use App\Service\ApiTokenManager;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Resolves a bearer token presented to the `^/api` firewall into the owning
 * user. The token is hashed and matched against api_token.token_hash; a hit
 * stamps last-used and authenticates as that user, a miss is rejected.
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly ApiTokenManager $manager,
    ) {
    }

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        $token = $this->tokens->findOneByHash(ApiTokenManager::hash($accessToken));

        if ($token === null || $token->getUser() === null) {
            throw new BadCredentialsException('Invalid API token.');
        }

        $this->manager->touch($token);
        $user = $token->getUser();

        // Carry the resolved user directly so the provider isn't queried again.
        return new UserBadge($user->getUserIdentifier(), static fn (): UserInterface => $user);
    }
}

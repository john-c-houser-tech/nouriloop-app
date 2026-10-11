<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mints and verifies API tokens. The plaintext (`nutri_<64 hex>`) is returned
 * once at creation for the user to copy; only its SHA-256 hash is stored, and
 * lookups hash the presented token and match on that.
 */
final class ApiTokenManager
{
    private const PREFIX = 'nutri_';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Creates and persists a token, returning the entity and the one-time
     * plaintext (never stored).
     *
     * @return array{0: ApiToken, 1: string} [token, plaintext]
     */
    public function create(User $user, string $name): array
    {
        $plaintext = self::generatePlaintext();

        $token = new ApiToken();
        $token->setUser($user);
        $token->setName(trim($name) ?: 'API token');
        $token->setTokenHash(self::hash($plaintext));

        $this->em->persist($token);
        $this->em->flush();

        return [$token, $plaintext];
    }

    /**
     * Stamps a token's last-used time (called on each successful auth). Skips
     * the write when it was already updated this minute to avoid a DB write on
     * every single API request.
     */
    public function touch(ApiToken $token, ?\DateTimeImmutable $now = null): void
    {
        $now  = $now ?? new \DateTimeImmutable();
        $last = $token->getLastUsedAt();

        if ($last !== null && $now->getTimestamp() - $last->getTimestamp() < 60) {
            return;
        }

        $token->setLastUsedAt($now);
        $this->em->flush();
    }

    public static function generatePlaintext(): string
    {
        return self::PREFIX . bin2hex(random_bytes(32));
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}

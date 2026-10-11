<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InvitationCode;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mints and redeems registration invitation codes. A code is a random 32-char
 * hex string, good for 48 hours, single-use. Stored in plaintext (it's displayed
 * back to the admin), unlike API tokens which are hashed.
 */
final class InvitationCodeManager
{
    public const TTL_HOURS = 48;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Creates and persists a fresh code minted by the given admin. A beta-tester
     * code grants its redeemer a time-limited complimentary Loop+ (applied at
     * registration; see SubscriptionService::BETA_COMP_DURATION).
     */
    public function create(User $admin, bool $betaTester = false): InvitationCode
    {
        $code = new InvitationCode();
        $code->setCode(self::generateCode());
        $code->setExpiresAt($code->getCreatedAt()->modify('+' . self::TTL_HOURS . ' hours'));
        $code->setCreatedBy($admin);
        $code->setBetaTester($betaTester);

        $this->em->persist($code);
        $this->em->flush();

        return $code;
    }

    /**
     * Marks a code as redeemed by the given user. The caller is responsible for
     * flushing (typically alongside persisting the new user in one unit of work).
     */
    public function redeem(InvitationCode $code, User $user, ?\DateTimeImmutable $now = null): void
    {
        $code->setUsedBy($user);
        $code->setUsedAt($now ?? new \DateTimeImmutable());
    }

    public static function generateCode(): string
    {
        return bin2hex(random_bytes(16));
    }
}

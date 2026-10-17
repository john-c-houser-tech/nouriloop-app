<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single write point for email-ownership verification (soft-gate — see the
 * User::$emailVerifiedAt docblock). A user is "verified" once any flow proves
 * they control the inbox; the stamp points are:
 *
 *  - a magic-link sign-in (LoginLinkVerificationListener — this also covers the
 *    welcome email's "Confirm your email" CTA, which is itself a login link);
 *  - a completed password reset (ResetPasswordController — the emailed token
 *    proves the same thing).
 *
 * One stamp point is not an inbox proof: granting admin from the CLI
 * (MakeAdminCommand), where server access vouches for the account.
 *
 * Because verification is stamped opportunistically, users verify themselves as
 * they naturally use email flows — no dedicated ceremony required.
 */
class EmailVerifier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Idempotent: the first proof wins, later proofs are no-ops. */
    public function markVerified(User $user): void
    {
        if ($user->isEmailVerified()) {
            return;
        }

        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em->flush();
    }
}

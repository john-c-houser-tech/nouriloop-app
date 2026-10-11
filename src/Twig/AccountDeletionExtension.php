<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Service\AccountDeletionService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `deletion_scheduled_for()` — the currently logged-in user's scheduled account-purge
 * date, or null if no deletion is pending. Backs the app-wide banner in base.html.twig;
 * routes through AccountDeletionService so the grace-period math lives in one place.
 */
class AccountDeletionExtension extends AbstractExtension
{
    public function __construct(
        private readonly AccountDeletionService $accountDeletion,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('deletion_scheduled_for', $this->deletionScheduledFor(...)),
        ];
    }

    public function deletionScheduledFor(): ?\DateTimeImmutable
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        return $this->accountDeletion->scheduledPurgeDate($user);
    }
}

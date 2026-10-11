<?php

declare(strict_types=1);

namespace App\Twig;

use App\Dto\OnboardingProgress;
use App\Entity\User;
use App\Service\OnboardingService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Feeds the first-login onboarding overlay (included in base.html.twig):
 *   - `onboarding_progress()` → the per-step OnboardingProgress for the current user
 * Returns null when nobody is logged in. Computed from already-hydrated User fields
 * (no DB query), so it's cheap to call on every authenticated page render.
 */
class OnboardingExtension extends AbstractExtension
{
    public function __construct(
        private readonly OnboardingService $onboarding,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('onboarding_progress', $this->progress(...)),
        ];
    }

    public function progress(): ?OnboardingProgress
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        return $this->onboarding->progress($user);
    }
}

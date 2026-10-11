<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\OnboardingProgress;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single source of truth for the first-login onboarding state: marks steps done,
 * exposes a progress snapshot for the overlay, and toggles the permanent dismiss.
 *
 * Steps are marked server-side at the point the user completes the real action
 * (MealController::saveFromState, GoalFactory::create, the onboarding notifications
 * form, HealthMetricsController::import) — the overlay re-renders from progress() on
 * the next page load, so there is no JS step endpoint or polling.
 */
class OnboardingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Mark a step ('meal'|'goal'|'notifications'|'health') done and flush. Idempotent —
     * a repeat keeps the first timestamp. No-op once onboarding is complete or dismissed,
     * so completing a feature later (e.g. logging another meal) never re-stamps state.
     */
    public function markStep(User $user, string $step): void
    {
        if (!$user->needsOnboarding()) {
            return;
        }

        $user->markOnboardingStep($step, new \DateTimeImmutable());
        $this->em->flush();
    }

    public function progress(User $user): OnboardingProgress
    {
        return new OnboardingProgress(
            mealDone: $user->getOnboardingMealAt() !== null,
            goalDone: $user->getOnboardingGoalAt() !== null,
            notificationsDone: $user->getOnboardingNotificationsAt() !== null,
            healthDone: $user->getOnboardingHealthAt() !== null,
        );
    }

    public function dismiss(User $user): void
    {
        $user->setOnboardingDismissed(true);
        $this->em->flush();
    }

    public function enable(User $user): void
    {
        $user->setOnboardingDismissed(false);
        $this->em->flush();
    }
}

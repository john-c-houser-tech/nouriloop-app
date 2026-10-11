<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Snapshot of a user's onboarding progress, used to render the overlay's progress
 * pips and decide which step is active. The health step is "extra credit" and does
 * not count toward allRequiredDone / requiredRemaining.
 */
final class OnboardingProgress
{
    public function __construct(
        public readonly bool $mealDone,
        public readonly bool $goalDone,
        public readonly bool $notificationsDone,
        public readonly bool $healthDone,
    ) {
    }

    public function allRequiredDone(): bool
    {
        return $this->mealDone && $this->goalDone && $this->notificationsDone;
    }

    /** Count of the three required steps still outstanding (0–3). */
    public function requiredRemaining(): int
    {
        return (int) !$this->mealDone + (int) !$this->goalDone + (int) !$this->notificationsDone;
    }

    /**
     * The slug of the first incomplete step in order (meal → goal → notifications →
     * health), or null when everything is done — drives which card the overlay opens on.
     */
    public function activeStep(): ?string
    {
        return match (true) {
            !$this->mealDone => 'meal',
            !$this->goalDone => 'goal',
            !$this->notificationsDone => 'notifications',
            !$this->healthDone => 'health',
            default => null,
        };
    }
}

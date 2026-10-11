<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\MealEntryRepository;
use Psr\Log\LoggerInterface;

/**
 * Flags a likely duplicate meal: the same photo logged at the same date/time for the
 * same user. Each save copies the photo to a unique filename, so "same picture" can't
 * be a path match — it's a content (md5) comparison, scoped to meals already logged at
 * the exact same `loggedAt`. The meal-save flow uses this to warn the user (and log a
 * warning) when a reused photo is saved twice for the same moment.
 */
class MealDuplicateChecker
{
    public function __construct(
        private readonly MealEntryRepository $meals,
        private readonly LoggerInterface $logger,
        private readonly string $uploadsDir,
    ) {
    }

    /**
     * Whether $user already has a meal at exactly $loggedAt whose stored photo is
     * byte-identical to the one at $imageWebPath. Call before persisting the new meal
     * so it isn't compared against itself. Logs a warning when a duplicate is found.
     *
     * @param string|null $imageWebPath the new meal's stored path, e.g. "uploads/meals/xyz.jpg"
     */
    public function isDuplicate(User $user, \DateTimeImmutable $loggedAt, ?string $imageWebPath): bool
    {
        $newHash = $this->hash($imageWebPath);
        if ($newHash === null) {
            return false;
        }

        foreach ($this->meals->findBy(['user' => $user, 'loggedAt' => $loggedAt]) as $existing) {
            if ($this->hash($existing->getImagePath()) === $newHash) {
                $this->logger->warning(
                    'Possible duplicate meal: same photo already logged at the same date/time.',
                    [
                        'user'           => $user->getUserIdentifier(),
                        'loggedAt'       => $loggedAt->format(\DateTimeImmutable::ATOM),
                        'existingMealId' => $existing->getId(),
                    ],
                );

                return true;
            }
        }

        return false;
    }

    private function hash(?string $imageWebPath): ?string
    {
        if ($imageWebPath === null || $imageWebPath === '') {
            return null;
        }
        $file = $this->uploadsDir . '/' . basename($imageWebPath);
        if (!is_file($file)) {
            return null;
        }
        $hash = @md5_file($file);

        return $hash === false ? null : $hash;
    }
}

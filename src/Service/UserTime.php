<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * The single source of "what is now/today for this user", in their own timezone.
 *
 * NouriLoop stores per-user data as the user's local wall-clock (health imports drop
 * the device offset; meal/symptom times come from the user's device/input). So to
 * keep day-bucketing and "today" correct we must build every range boundary in the
 * user's zone: setTimezone() preserves the instant but re-expresses the wall-clock,
 * and the resulting DateTimeImmutable's format('Y-m-d H:i:s') then matches the
 * stored rows (and Doctrine binds query params by that same wall-clock).
 *
 * The optional $clock argument lets callers (and the console commands' --now option)
 * inject a reference instant for testing/replay.
 */
final class UserTime
{
    public function zone(User $user): \DateTimeZone
    {
        return new \DateTimeZone($user->getTimezone());
    }

    /**
     * The current instant expressed in the user's timezone.
     */
    public function now(User $user, ?\DateTimeImmutable $clock = null): \DateTimeImmutable
    {
        return ($clock ?? new \DateTimeImmutable())->setTimezone($this->zone($user));
    }

    /**
     * Midnight at the start of the user's local "today".
     */
    public function startOfToday(User $user, ?\DateTimeImmutable $clock = null): \DateTimeImmutable
    {
        return $this->now($user, $clock)->setTime(0, 0);
    }

    /**
     * Midnight $days days before the user's local "today".
     */
    public function startOfDaysAgo(User $user, int $days, ?\DateTimeImmutable $clock = null): \DateTimeImmutable
    {
        return $this->startOfToday($user, $clock)->modify("-{$days} days");
    }

    /**
     * Midnight at the start of the user's local "tomorrow" (exclusive range end).
     */
    public function startOfTomorrow(User $user, ?\DateTimeImmutable $clock = null): \DateTimeImmutable
    {
        return $this->startOfToday($user, $clock)->modify('+1 day');
    }

    /**
     * The user's local hour of day (0–23).
     */
    public function localHour(User $user, ?\DateTimeImmutable $clock = null): int
    {
        return (int) $this->now($user, $clock)->format('G');
    }
}

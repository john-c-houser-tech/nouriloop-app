<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parses the `--now` testing/replay option the scheduled commands share
 * (send-notifications, run-goal-checkins, purge-deleted-accounts).
 *
 * PHP normalizes impossible calendar dates (2026-02-30 → 2026-03-02) with only an
 * internal warning, so a typo would silently replay a different day — rejected
 * here, the same check GrantCompCommand applies to --expires.
 */
final class NowOption
{
    /** @throws \InvalidArgumentException with a user-facing message */
    public static function parse(mixed $raw): \DateTimeImmutable
    {
        if ($raw === null) {
            return new \DateTimeImmutable();
        }

        $raw = (string) $raw;
        try {
            $now = new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new \InvalidArgumentException(sprintf('Could not parse --now value "%s".', $raw));
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new \InvalidArgumentException(sprintf(
                '--now "%s" is not a real date and time (it would silently become %s).',
                $raw,
                $now->format('Y-m-d H:i'),
            ));
        }

        return $now;
    }
}

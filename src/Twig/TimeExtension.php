<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Service\LocaleFormatter;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Locale-aware date display. Both filters take an ICU *skeleton* (`yMMMd`,
 * `MMMdjm`, `EEEMMMd`, …) — see LocaleFormatter for the resolution rules.
 *
 * `user_date` — formats a true instant (a server-generated timestamp such as a
 * goal's nextRunAt or an API token's createdAt) in the *current user's* timezone.
 *
 * `local_date` — formats a user-local wall-clock value (meal loggedAt, health
 * recordedAt, symptom dates) with NO timezone conversion: the stored numerals
 * render verbatim, only the wording/order is localized. Converting those would
 * double-apply the offset (see CLAUDE.md ▸ Timezones).
 *
 * Machine-readable output (form input values, `Y-m-d` URL params, the copyright
 * year) must keep Twig's plain `|date` filter.
 */
final class TimeExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly LocaleFormatter $localeFormatter,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('user_date', $this->userDate(...)),
            new TwigFilter('local_date', $this->localDate(...)),
        ];
    }

    public function userDate(\DateTimeInterface|string|null $value, string $skeleton = 'yMMMdjm'): string
    {
        if ($value === null) {
            return '';
        }

        $tz = new \DateTimeZone(
            $this->security->getUser() instanceof User
                ? $this->security->getUser()->getTimezone()
                : User::DEFAULT_TIMEZONE,
        );

        return $this->localeFormatter->date($value, $skeleton, $tz);
    }

    public function localDate(\DateTimeInterface|string|null $value, string $skeleton = 'yMMMd'): string
    {
        if ($value === null) {
            return '';
        }

        return $this->localeFormatter->date($value, $skeleton);
    }
}

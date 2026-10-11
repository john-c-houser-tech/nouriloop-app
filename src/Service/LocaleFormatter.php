<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Locale-aware display formatting for dates and numbers, keyed to the current
 * locale from LocaleSwitcher — correct on web requests (LocaleSubscriber) and
 * inside the delivery pipeline's runWithLocale() alike. Display-only: storage
 * stays user-local wall-clock and canonical units (see CLAUDE.md ▸ Timezones,
 * ▸ i18n).
 *
 * Dates take an ICU *skeleton* (`yMMMd`, `MMMdjm`, `EEEMMMd`, …), resolved to
 * the locale's own field order/separators via IntlDatePatternGenerator — `es`
 * renders "18 jul 2026" where `en` renders "Jul 18, 2026" from the same call.
 * Formatters are memoized per (locale, skeleton, timezone).
 */
class LocaleFormatter
{
    /** @var array<string, \IntlDateFormatter> */
    private array $dateFormatters = [];

    /** @var array<string, \NumberFormatter> */
    private array $numberFormatters = [];

    public function __construct(
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    /**
     * Format an instant per the current locale. With no $tz the value's own
     * timezone is used, so stored wall-clock numerals render verbatim; pass a
     * timezone to convert (e.g. a server-frame scheduling instant into the
     * user's zone).
     */
    public function date(\DateTimeInterface|string $value, string $skeleton, ?\DateTimeZone $tz = null): string
    {
        if (\is_string($value)) {
            // An ATOM string keeps its embedded offset, so formatting in the
            // value's own zone renders the stored numerals verbatim.
            $value = new \DateTimeImmutable($value);
        }

        $tz ??= $value->getTimezone();
        $locale = $this->localeSwitcher->getLocale();
        $key = $locale . '|' . $skeleton . '|' . $tz->getName();

        if (!isset($this->dateFormatters[$key])) {
            $pattern = (new \IntlDatePatternGenerator($locale))->getBestPattern($skeleton);
            $formatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                $tz,
            );
            $formatter->setPattern($pattern);
            $this->dateFormatters[$key] = $formatter;
        }

        return (string) $this->dateFormatters[$key]->format($value);
    }

    /**
     * Format a number per the current locale (grouping + decimal separator),
     * with a fixed number of fraction digits — the PHP-side twin of Twig's
     * `format_number` filter.
     */
    public function number(int|float $value, int $fractionDigits = 0): string
    {
        $locale = $this->localeSwitcher->getLocale();
        $key = $locale . '|' . $fractionDigits;

        if (!isset($this->numberFormatters[$key])) {
            $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $fractionDigits);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $fractionDigits);
            $this->numberFormatters[$key] = $formatter;
        }

        return (string) $this->numberFormatters[$key]->format($value);
    }
}

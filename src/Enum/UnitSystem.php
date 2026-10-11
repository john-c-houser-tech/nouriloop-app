<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A user's preferred unit system for display. Storage stays canonical (kg, mg/dL);
 * this only governs how weight and blood glucose are shown (see UnitConverter).
 */
enum UnitSystem: string
{
    case Metric   = 'metric';
    case Imperial = 'imperial';

    public function label(): string
    {
        return match ($this) {
            self::Metric   => 'Metric (kg, mmol/L)',
            self::Imperial => 'Imperial / US (lb, mg/dL)',
        };
    }

    /** Translation key for UI rendering (i18n); label() stays for PHP contexts. */
    public function transKey(): string
    {
        return 'settings.unit_system.' . $this->value;
    }

    /**
     * Default unit system for a new user, inferred from their IANA timezone. Only
     * the US (plus a couple of small exceptions we don't model) uses imperial for
     * body weight + glucose, so every US zone → imperial and everything else —
     * including the rest of the Americas — defaults to metric. The full, current US
     * zone set comes from the tz database (covers all Indiana/North Dakota/Alaska
     * sub-zones), so it never drifts out of date.
     */
    public static function defaultForTimezone(string $timezone): self
    {
        /** @var list<string>|null $usZones */
        static $usZones = null;
        $usZones ??= \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, 'US');

        return in_array($timezone, $usZones, true) ? self::Imperial : self::Metric;
    }
}

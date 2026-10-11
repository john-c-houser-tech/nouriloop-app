<?php

declare(strict_types=1);

namespace App\Enum;

use Symfony\Component\HttpFoundation\Request;

/**
 * The UI/AI languages NouriLoop supports — the single whitelist behind the
 * `User.locale` column, the Settings ▸ Language form, registration's
 * Accept-Language detection, and LocaleSubscriber. Regional variants (es_MX,
 * en_GB, …) carry only small override catalogs; the Symfony translator falls
 * back es_MX → es → en automatically (config/packages/translation.yaml).
 */
enum SupportedLocale: string
{
    // `en` must stay the first case: Request::getPreferredLanguage() returns the
    // whitelist's first entry when nothing in Accept-Language matches.
    case English       = 'en';
    case EnglishUk     = 'en_GB';
    case German        = 'de';
    case Spanish       = 'es';
    case SpanishMexico = 'es_MX';
    case SpanishSpain  = 'es_ES';
    case French        = 'fr';
    case Russian       = 'ru';
    case Hindi         = 'hi';

    public const DEFAULT = self::English;

    /**
     * The `{_locale}` route requirement for the locale-prefixed public
     * conversion pages (i18n Phase 4): every non-English locale as a dashed,
     * lowercase URL segment (`/es-mx/about`). Bare URLs stay English — `en`
     * deliberately has no prefix, so it's absent here.
     */
    public const URL_PREFIX_REQUIREMENT = 'en-gb|es|es-mx|es-es|de|fr|ru|hi';

    /** Dashed lowercase URL segment for prefixed public routes ('' for en = bare URL). */
    public function urlPrefix(): string
    {
        return $this === self::English ? '' : str_replace('_', '-', strtolower($this->value));
    }

    /** BCP-47 tag for hreflang/lang attributes (en-GB, es-MX, …). */
    public function hreflang(): string
    {
        return str_replace('_', '-', $this->value);
    }

    /** Resolve a URL prefix segment ('es-mx') back to a locale; null on no match. */
    public static function fromUrlPrefix(string $prefix): ?self
    {
        foreach (self::cases() as $locale) {
            if ($locale !== self::English && $locale->urlPrefix() === strtolower($prefix)) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * Native-language display name. Deliberately NOT run through the translator —
     * a language's own name is the one label every speaker recognises regardless
     * of the currently-active UI language.
     */
    public function label(): string
    {
        return match ($this) {
            self::English       => 'English',
            self::EnglishUk     => 'English (UK)',
            self::German        => 'Deutsch',
            self::Spanish       => 'Español',
            self::SpanishMexico => 'Español (México)',
            self::SpanishSpain  => 'Español (España)',
            self::French        => 'Français',
            self::Russian       => 'Русский',
            self::Hindi         => 'हिन्दी',
        };
    }

    /**
     * The language name used in AI prompt instructions ("Respond in …") —
     * always stated in English, since the base prompts are English.
     */
    public function promptLanguage(): string
    {
        return match ($this) {
            self::English       => 'English',
            self::EnglishUk     => 'British English',
            self::German        => 'German',
            self::Spanish       => 'Spanish',
            self::SpanishMexico => 'Mexican Spanish',
            self::SpanishSpain  => 'European Spanish',
            self::French        => 'French',
            self::Russian       => 'Russian',
            self::Hindi         => 'Hindi',
        };
    }

    /** @return list<string> All supported locale tags, `en` first (the no-match default). */
    public static function values(): array
    {
        return array_map(static fn (self $l): string => $l->value, self::cases());
    }

    /**
     * Best supported match for the request's Accept-Language header, English
     * otherwise. getPreferredLanguage() restricts to (and normalises against)
     * the passed whitelist — verified: `es-MX` → `es_MX`, no match → `en` —
     * so the tryFrom fallback is belt-and-braces only.
     */
    public static function fromRequest(Request $request): self
    {
        return self::tryFrom((string) $request->getPreferredLanguage(self::values())) ?? self::DEFAULT;
    }
}

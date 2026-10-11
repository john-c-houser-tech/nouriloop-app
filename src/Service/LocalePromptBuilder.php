<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\SupportedLocale;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Turns a user's locale into the compact prompt instructions that make every
 * Claude surface answer in their language — the locale sibling of
 * TonePromptBuilder (i18n Phase 3). English returns '' everywhere: the base
 * prompts are English, so no instruction is the correct instruction.
 *
 * Two flavors:
 *  - {@see narrative()} / {@see forUser()} / {@see forCurrentLocale()} — for
 *    prose surfaces (goal check-ins, Pattern Coach, Predict, goal-setup chat,
 *    help assistant). Restates the hard guardrails as surviving translation:
 *    the educational-not-medical-advice framing and the plain-prose rule apply
 *    in the target language too.
 *  - {@see mealAnalysisJson()} — for the meal-photo JSON prompt. The
 *    user-facing "description"/"questions" fields localize, but ingredient
 *    "name" fields MUST stay English: InflammationScoreService's food-category
 *    adjustments are English keyword matches on ingredient names, and localized
 *    names would silently stop them firing (a quiet scoring regression). Also
 *    carries the regional-dish rule ("don't map to the closest US analog").
 *
 * Include the locale in any cache key for AI output (the Pattern Coach
 * narrative cache already folds it in) so a language switch busts the cache.
 */
final class LocalePromptBuilder
{
    public function __construct(
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    public function forUser(User $user): string
    {
        return $this->narrative($user->getLocale());
    }

    /** The current request/CLI locale — for surfaces serving anonymous visitors too (help assistant). */
    public function currentLocale(): SupportedLocale
    {
        return SupportedLocale::tryFrom($this->localeSwitcher->getLocale()) ?? SupportedLocale::English;
    }

    public function forCurrentLocale(): string
    {
        return $this->narrative($this->currentLocale());
    }

    public function narrative(SupportedLocale $locale): string
    {
        if ($locale === SupportedLocale::English) {
            return '';
        }
        $language = $locale->promptLanguage();

        return "Respond in {$language}. Use culturally appropriate food terminology and typical "
            . "regional portion references, and do not assume US dietary norms. Every required "
            . "framing sentence — including the educational-wellness-not-medical-advice closing — "
            . "must be written in {$language} as well, and the plain-prose/no-Markdown rule "
            . 'still applies. ';
    }

    public function mealAnalysisJson(SupportedLocale $locale): string
    {
        if ($locale === SupportedLocale::English) {
            return '';
        }
        $language = $locale->promptLanguage();

        return "The user speaks {$language}: write the \"description\" and every entry in "
            . "\"questions\" in {$language}. CRITICAL: every ingredient \"name\" MUST remain in "
            . 'English regardless of the user\'s language — internal analysis depends on English '
            . 'ingredient names — and all JSON keys and units stay exactly as specified. If the '
            . 'food is a regionally common dish, recognize it and name it accurately in the '
            . 'description by its regional name — do not map it to the closest American analog. ';
    }

    /**
     * The recipe-import sibling of {@see mealAnalysisJson}: "title" and
     * "questions" localize, ingredient names stay English (same inflammation
     * keyword-matching dependency), and "instructions" are a transcription of
     * the user's own card — reproduced in whatever language they're written
     * in, never translated.
     */
    public function recipeAnalysisJson(SupportedLocale $locale): string
    {
        if ($locale === SupportedLocale::English) {
            return '';
        }
        $language = $locale->promptLanguage();

        return "The user speaks {$language}: write the \"title\" and every entry in "
            . "\"questions\" in {$language}. Transcribe \"instructions\" in whatever language "
            . 'the recipe itself is written in — do not translate them. CRITICAL: every '
            . 'ingredient "name" MUST remain in English regardless of the recipe\'s or the '
            . 'user\'s language — internal analysis depends on English ingredient names — '
            . 'and all JSON keys and units stay exactly as specified. ';
    }

    /**
     * Non-English narratives run ~20-30% longer in tokens (es/de/ru especially),
     * so give them headroom — otherwise the required closing sentence can
     * truncate at chatty tone settings.
     */
    public function maxTokens(SupportedLocale $locale, int $base): int
    {
        return $locale === SupportedLocale::English ? $base : (int) ceil($base * 1.3);
    }
}

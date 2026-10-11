<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\SupportedLocale;
use App\Service\LocalePromptBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\LocaleSwitcher;

final class LocalePromptBuilderTest extends TestCase
{
    private function builder(string $currentLocale = 'en'): LocalePromptBuilder
    {
        return new LocalePromptBuilder(new LocaleSwitcher($currentLocale, []));
    }

    public function testEnglishEmitsNothingSoBasePromptsStayByteIdentical(): void
    {
        $builder = $this->builder();

        self::assertSame('', $builder->narrative(SupportedLocale::English));
        self::assertSame('', $builder->mealAnalysisJson(SupportedLocale::English));
        self::assertSame('', $builder->recipeAnalysisJson(SupportedLocale::English));
        self::assertSame('', $builder->forCurrentLocale());
    }

    public function testRecipeRuleLocalizesTitleButTranscribesInstructionsVerbatim(): void
    {
        $text = $this->builder()->recipeAnalysisJson(SupportedLocale::Spanish);

        self::assertStringContainsString('"title"', $text);
        self::assertStringContainsString('"questions"', $text);
        // The card's own steps are transcribed, never translated…
        self::assertStringContainsString('do not translate', $text);
        // …and ingredient names stay English (inflammation keyword matching).
        self::assertStringContainsString('MUST remain in English', $text);
    }

    public function testNarrativeNamesTheLanguageAndRestatesTheGuardrails(): void
    {
        $text = $this->builder()->narrative(SupportedLocale::SpanishMexico);

        self::assertStringContainsString('Respond in Mexican Spanish', $text);
        // The disclaimers + plain-prose rule must survive translation.
        self::assertStringContainsString('not-medical-advice', $text);
        self::assertStringContainsString('no-Markdown', $text);
    }

    public function testMealAnalysisRuleLocalizesProseButPinsEnglishIngredientNames(): void
    {
        $text = $this->builder()->mealAnalysisJson(SupportedLocale::Spanish);

        self::assertStringContainsString('"description"', $text);
        self::assertStringContainsString('"questions"', $text);
        self::assertStringContainsString('MUST remain in English', $text);
        // Regional dishes are named, not mapped to a US analog.
        self::assertStringContainsString('regional name', $text);
    }

    public function testForUserReadsTheSavedLocale(): void
    {
        $user = new User();
        $user->setEmail('locale@test.local');
        $user->setLocale(SupportedLocale::German);

        self::assertStringContainsString('Respond in German', $this->builder()->forUser($user));
    }

    public function testCurrentLocaleFollowsTheSwitcherAndFallsBackToEnglish(): void
    {
        self::assertSame(SupportedLocale::Spanish, $this->builder('es')->currentLocale());
        // An unexpected switcher value degrades to English, never throws.
        self::assertSame(SupportedLocale::English, $this->builder('ja')->currentLocale());
    }

    public function testMaxTokensGivesNonEnglishHeadroom(): void
    {
        $builder = $this->builder();

        self::assertSame(600, $builder->maxTokens(SupportedLocale::English, 600));
        self::assertSame(780, $builder->maxTokens(SupportedLocale::Spanish, 600));
        self::assertSame(666, $builder->maxTokens(SupportedLocale::Russian, 512));
    }
}

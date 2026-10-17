<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Where a user's own AI key sends their calls (Settings ▸ AI provider). "Let NouriLoop
 * choose" fills a blank model slot with {@see defaultModel()}; a custom endpoint has
 * no sensible default, so its text model is required.
 */
enum UserAiProvider: string
{
    case Anthropic = 'anthropic';
    case OpenRouter = 'openrouter';
    case Custom = 'custom';

    public const OPENROUTER_BASE_URL = 'https://openrouter.ai/api/v1';

    /**
     * The model a blank slot means, or null when there is none (a custom endpoint).
     * The same model the hosted service runs, so quality matches; OpenRouter's id for
     * it was checked against openrouter.ai/api/v1/models on 2026-10-12 (reads images).
     */
    public function defaultModel(): ?string
    {
        return match ($this) {
            self::Anthropic => AnthropicClient::MODEL,
            self::OpenRouter => 'anthropic/claude-sonnet-4.6',
            self::Custom => null,
        };
    }

    /** The provider's own name for status text; null for a custom service (translated instead). */
    public function displayName(): ?string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic',
            self::OpenRouter => 'OpenRouter',
            self::Custom => null,
        };
    }

    /** The radio-button label ("My Anthropic key"), not the provider's name. */
    public function transKey(): string
    {
        return 'settings.ai.provider.' . $this->value;
    }
}

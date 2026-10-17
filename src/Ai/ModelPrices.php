<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * List prices per million tokens, for the cost estimate on each recorded call
 * ({@see MeteredAiClient}). Keyed by provider, then by the exact model id the client
 * sends: a price is a provider's price, so the same model id reached through an
 * OpenAI-compatible proxy or router is not given Anthropic's rate. Anything not
 * listed (an OpenRouter route, a local Ollama model) gets no estimate rather than a
 * guess: its calls are still recorded with their tokens.
 *
 * Anthropic first-party rates, from the Claude API model table (cached 2026-09-25).
 * Update this list when a price changes or the app adopts a new model; the Full
 * tier's price is derived from these estimates (App Development Plan Q2b).
 */
final class ModelPrices
{
    /**
     * provider => model => USD per million tokens.
     *
     * @var array<string, array<string, array{input: float, output: float}>>
     */
    public const PER_MILLION_TOKENS = [
        AnthropicClient::PROVIDER => [
            'claude-sonnet-4-6' => ['input' => 3.0, 'output' => 15.0],
            'claude-haiku-4-5-20251001' => ['input' => 1.0, 'output' => 5.0],
            'claude-haiku-4-5' => ['input' => 1.0, 'output' => 5.0],
            'claude-sonnet-5-5' => ['input' => 2.0, 'output' => 10.0],
            'claude-opus-5-5' => ['input' => 4.0, 'output' => 20.0],
        ],
    ];

    /**
     * Estimated cost of one call in micro-dollars (USD × 1,000,000), or null when the
     * provider has no listed price for the model. Micro-dollars keep a single call's
     * cost (often well under a cent) an exact integer.
     */
    public static function costMicros(string $provider, string $model, int $inputTokens, int $outputTokens): ?int
    {
        $price = self::PER_MILLION_TOKENS[$provider][$model] ?? null;
        if ($price === null) {
            return null;
        }

        // tokens × ($ per 1,000,000 tokens) × 1,000,000 micro-dollars per $ = tokens × price.
        return (int) round($inputTokens * $price['input'] + $outputTokens * $price['output']);
    }
}

<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\NutritionData;

/**
 * What Claude read off a photographed recipe (cookbook page, printout, or
 * handwritten card): the transcription plus a nutrition estimate for the WHOLE
 * batch as written. The per-serving division happens at save time on the
 * Confirm screen, once the user has verified the servings count.
 */
class RecipeAnalysis implements TokenUsage
{
    public function __construct(
        public readonly string $title,
        /** The stated yield (or Claude's best guess — see questions); null when unreadable. */
        public readonly ?float $servings,
        /** Nutrition totals for the entire recipe, NOT per serving. */
        public readonly NutritionData $nutrition,
        public readonly float $confidence,
        /** @var string[] */
        public readonly array $questions,
        /** @var array<array{name: string, amount: string, unit: string}> */
        public readonly array $ingredients,
        /** Transcribed preparation steps ('' when none are visible). */
        public readonly string $instructions,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {
    }

    /**
     * Total tokens this analysis cost — recorded on the favorite so the reuse
     * savings report can credit each later log against it.
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}

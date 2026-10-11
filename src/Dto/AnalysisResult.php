<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\NutritionData;

class AnalysisResult implements TokenUsage
{
    private const MAX_ROUNDS = 3;

    public function __construct(
        public readonly NutritionData $nutrition,
        public readonly float $confidence,
        public readonly string $description,
        /** @var string[] */
        public readonly array $questions,
        /** @var array<array{name: string, amount: string, unit: string}> */
        public readonly array $ingredients = [],
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {
    }

    /**
     * Total tokens this analysis cost — the figure a saved favorite avoids
     * spending on each later reuse.
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function needsClarification(int $currentRound = 1): bool
    {
        return $this->confidence < ConfidenceLevel::CLARIFY_THRESHOLD
            && !empty($this->questions)
            && $currentRound <= self::MAX_ROUNDS;
    }
}

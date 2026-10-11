<?php

declare(strict_types=1);

namespace App\Dto;

class SupplementAnalysis implements TokenUsage
{
    /**
     * @param array<string, float> $nutrients per-serving amounts keyed by RDA key
     */
    public function __construct(
        public readonly string $name,
        public readonly string $servingSize,
        public readonly float $confidence,
        public readonly array $nutrients,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {
    }

    /** Total tokens the scan cost — what the ledger books against the allowance. */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}

<?php

declare(strict_types=1);

namespace App\Dto;

final class PredictiveInsight
{
    /**
     * @param list<array{
     *   dimension: string, label: string, currentValue: float|string,
     *   projectedValue: float|string, unit: string, direction: string
     * }> $beforeAfter
     */
    public function __construct(
        public readonly string $narrative,
        public readonly array $beforeAfter,
        public readonly string $confidenceNote,
        public readonly bool $isFreeTextEstimate = false,
    ) {
    }
}

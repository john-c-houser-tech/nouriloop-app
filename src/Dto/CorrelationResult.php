<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One symptom↔factor correlation at one lag (0 = same day, 1 = next day), with the
 * Pearson coefficient, sample size, and a two-tailed significance test. Surfaced
 * only when {@see $significant} (n ≥ MIN_N and p < 0.05) so noise stays out of the UI.
 */
final class CorrelationResult
{
    public function __construct(
        public readonly string $factor,
        public readonly string $label,
        public readonly float $r,
        public readonly int $n,
        public readonly float $pValue,
        public readonly int $lagDays,
        public readonly bool $significant,
    ) {
    }

    /** True when the factor and symptom tend to rise together. */
    public function isPositive(): bool
    {
        return $this->r > 0;
    }
}

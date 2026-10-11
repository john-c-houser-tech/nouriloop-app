<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * The glucose response in the window following a single meal — the curve plus the
 * scalar metrics derived from it. Immutable; produced by
 * {@see \App\Service\PostprandialResponseService}, consumed by the meal-detail card.
 *
 * All values are in the user's stored wall-clock frame (meal `loggedAt` and glucose
 * `recorded_at` share it, offset-dropped), so `minutes` is a plain difference with no
 * timezone conversion. Educational wellness signal, not a medical measure.
 */
final class PostprandialResponse
{
    /**
     * @param list<array{minutes: int, value: float}> $series readings as minutes-from-meal (negative = pre-meal), mg/dL
     * @param float|null $baseline            pre-meal baseline (mean of readings before the meal), mg/dL
     * @param float|null $peak                highest post-meal reading, mg/dL
     * @param int|null   $timeToPeakMin       minutes from meal to the peak
     * @param float|null $delta               peak − baseline, mg/dL
     * @param float|null $iauc                incremental area under the curve above baseline (mg/dL·min)
     * @param int|null   $returnToBaselineMin minutes back to baseline after the peak, or null if not within the window
     * @param int        $readingCount        total readings in the window (pre + post)
     * @param bool       $hasBaseline         whether a true pre-meal baseline was available
     * @param bool       $hasCurve            whether there are enough post-meal readings to draw a response curve
     * @param string     $confidence          'high' | 'moderate' | 'low'
     */
    public function __construct(
        public readonly array $series,
        public readonly ?float $baseline,
        public readonly ?float $peak,
        public readonly ?int $timeToPeakMin,
        public readonly ?float $delta,
        public readonly ?float $iauc,
        public readonly ?int $returnToBaselineMin,
        public readonly int $readingCount,
        public readonly bool $hasBaseline,
        public readonly bool $hasCurve,
        public readonly string $confidence,
    ) {
    }
}

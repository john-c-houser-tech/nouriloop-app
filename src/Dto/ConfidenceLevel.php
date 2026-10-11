<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * A 0.0–1.0 AI confidence score plus the single source of truth for how it's
 * bucketed and presented. Shared by AnalysisResult (the analyzer's
 * clarification loop) and the meal review screen, so the thresholds live in
 * exactly one place and can't drift between PHP and Twig.
 */
final class ConfidenceLevel
{
    /**
     * At/above this the estimate is treated as solid: the analyzer stops asking
     * clarifying questions and the review screen leads with "Save to log"
     * rather than "Re-analyze".
     */
    public const CLARIFY_THRESHOLD = 0.75;

    public function __construct(private readonly float $score)
    {
    }

    /** Whole-percent for display, clamped to 0–100 against bad/legacy scores. */
    public function percent(): int
    {
        return (int) round(max(0.0, min(1.0, $this->score)) * 100);
    }

    public function isConfident(): bool
    {
        return $this->score >= self::CLARIFY_THRESHOLD;
    }

    public function label(): string
    {
        return match (true) {
            $this->score >= 0.85 => 'High',
            $this->score >= 0.60 => 'Medium',
            default              => 'Low',
        };
    }

    /** Translation key for the UI label (i18n) — same buckets as label(). */
    public function transKey(): string
    {
        return 'confidence.' . strtolower($this->label());
    }

    /** Bootstrap contextual color matching the label. */
    public function bootstrapColor(): string
    {
        return match (true) {
            $this->score >= 0.85 => 'success',
            $this->score >= 0.60 => 'warning',
            default              => 'danger',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One nutrient's before/after in a proposed re-analysis. Built only by
 * {@see \App\Service\NutritionDiff}; the template renders these verbatim.
 */
final class NutritionDiffRow
{
    /** Relative change below this reads as "unchanged" for display purposes. */
    private const EPSILON = 1e-9;

    public function __construct(
        /** NutritionData field name, e.g. `saturatedFat` — also the nutrient_full.* label key. */
        public readonly string $field,
        /** Display unit (kcal / g / mg / mcg). */
        public readonly string $unit,
        public readonly ?float $current,
        public readonly ?float $proposed,
    ) {
    }

    public function label(): string
    {
        return 'nutrient_full.' . $this->field;
    }

    public function changed(): bool
    {
        if ($this->current === null || $this->proposed === null) {
            return $this->current !== $this->proposed;
        }

        return abs($this->current - $this->proposed) > self::EPSILON;
    }

    public function delta(): ?float
    {
        if ($this->current === null || $this->proposed === null) {
            return null;
        }

        return $this->proposed - $this->current;
    }

    /**
     * Percentage change, or null when it can't be expressed — no current value,
     * no proposed value, or a current value of zero (any increase from zero is
     * infinite, not "+100%").
     */
    public function percentChange(): ?float
    {
        $delta = $this->delta();
        if ($delta === null || $this->current === null || abs($this->current) < self::EPSILON) {
            return null;
        }

        return $delta / $this->current * 100.0;
    }

    /**
     * 'added' (the AI estimated something that wasn't recorded), 'removed' (it
     * no longer estimates a value we hold), 'up', 'down', or 'same'.
     */
    public function direction(): string
    {
        if (!$this->changed()) {
            return 'same';
        }
        if ($this->current === null) {
            return 'added';
        }
        if ($this->proposed === null) {
            return 'removed';
        }

        return $this->proposed > $this->current ? 'up' : 'down';
    }
}

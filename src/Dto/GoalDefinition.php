<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\GoalAggregation;
use App\Enum\GoalDirection;
use App\Enum\GoalMetric;
use App\Support\Format;

/**
 * The structured, machine-readable definition of a nutrition goal — the artifact
 * the goal-setting conversation converges on (never free text). Persisted as the
 * NutritionGoal::$definition JSON column via toArray()/fromArray().
 */
final readonly class GoalDefinition
{
    public function __construct(
        public GoalMetric $metric,
        public GoalDirection $direction,
        public GoalAggregation $aggregation,
        public float $targetValue,
        /** How many days of meal history each check-in looks back over. */
        public int $evaluationWindowDays,
        /** The user's starting point, captured at goal creation (optional). */
        public ?float $baselineValue = null,
        /** Optional deadline for achieving the goal. */
        public ?\DateTimeImmutable $timeframeEnd = null,
        /** Top of the band for a Maintain (range) goal; null for at_most/at_least. */
        public ?float $targetMax = null,
    ) {
    }

    public function unit(): string
    {
        return $this->metric->unit();
    }

    /** A copy with the baseline filled in (captured at goal-creation time). */
    public function withBaseline(?float $baseline): self
    {
        return new self(
            $this->metric,
            $this->direction,
            $this->aggregation,
            $this->targetValue,
            $this->evaluationWindowDays,
            $baseline,
            $this->timeframeEnd,
            $this->targetMax,
        );
    }

    /** Is an aggregated value on the success side of this goal's target(s)? */
    public function isMet(float $value): bool
    {
        return $this->direction->isMet($value, $this->targetValue, $this->targetMax);
    }

    /**
     * Human sentence, e.g. "daily average sugar at most 30 g" or
     * "daily average calories around 2,000–2,200 kcal".
     */
    public function summary(): string
    {
        $target = $this->direction === GoalDirection::Maintain && $this->targetMax !== null
            ? Format::number($this->targetValue) . '–' . Format::number($this->targetMax)
            : Format::number($this->targetValue);

        return sprintf(
            '%s %s %s %s %s',
            $this->aggregation->label(),
            strtolower($this->metric->label()),
            $this->direction->label(),
            $target,
            $this->unit(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'metric'               => $this->metric->value,
            'direction'            => $this->direction->value,
            'aggregation'          => $this->aggregation->value,
            'targetValue'          => $this->targetValue,
            'targetMax'            => $this->targetMax,
            'evaluationWindowDays' => $this->evaluationWindowDays,
            'baselineValue'        => $this->baselineValue,
            'timeframeEnd'         => $this->timeframeEnd?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $end = $data['timeframeEnd'] ?? null;

        return new self(
            GoalMetric::from((string) $data['metric']),
            GoalDirection::from((string) $data['direction']),
            GoalAggregation::from((string) $data['aggregation']),
            (float) $data['targetValue'],
            (int) $data['evaluationWindowDays'],
            isset($data['baselineValue']) ? (float) $data['baselineValue'] : null,
            is_string($end) && $end !== '' ? new \DateTimeImmutable($end) : null,
            isset($data['targetMax']) ? (float) $data['targetMax'] : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GoalDefinition;
use App\Enum\GoalAggregation;
use App\Enum\GoalDirection;
use App\Enum\GoalMetric;
use App\Support\EnumHelper;

/**
 * Builds a GoalDefinition from a loose associative array, applying defaults and
 * tolerating bad/missing values. The single source of truth for "input → goal
 * definition", shared by the goal-setting conversation (which passes Claude's
 * proposal verbatim) and the manual form (which maps its request fields into this
 * shape) — so both produce identical goals.
 *
 * Expected keys (camelCase, matching the conversation proposal): metric, direction,
 * aggregation, targetValue, evaluationWindowDays, targetMax.
 *
 * @phpstan-type GoalDefinitionInput array{
 *     metric?: mixed, direction?: mixed, aggregation?: mixed,
 *     targetValue?: mixed, evaluationWindowDays?: mixed, targetMax?: mixed
 * }
 */
final class GoalDefinitionFactory
{
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): GoalDefinition
    {
        $direction = EnumHelper::resolve(GoalDirection::class, $data['direction'] ?? null, GoalDirection::AtMost);
        $max = $data['targetMax'] ?? null;

        return new GoalDefinition(
            EnumHelper::resolve(GoalMetric::class, $data['metric'] ?? null, GoalMetric::Sugar),
            $direction,
            EnumHelper::resolve(GoalAggregation::class, $data['aggregation'] ?? null, GoalAggregation::DailyAverage),
            (float) ($data['targetValue'] ?? 0),
            (int) ($data['evaluationWindowDays'] ?? 7),
            // An upper bound only applies to a "maintain" range goal.
            targetMax: $direction === GoalDirection::Maintain && is_numeric($max) ? (float) $max : null,
        );
    }
}

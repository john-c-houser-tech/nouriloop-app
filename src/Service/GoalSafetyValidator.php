<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GoalDefinition;
use App\Enum\GoalDirection;
use App\Enum\GoalMetric;
use App\Support\Format;

/**
 * Guards against obviously unsafe or nonsensical goal targets. Returns plain-text
 * *warnings* (not hard errors): NouriLoop gives nutritional feedback, not medical
 * advice, so it warns the user and makes them acknowledge rather than silently
 * forbidding. The check-in evaluation prompts are likewise non-prescriptive.
 *
 * Bounds are interpreted as daily-average values, the common case.
 */
class GoalSafetyValidator
{
    /** A diet floor below this many kcal/day is widely flagged as unsafe. */
    private const CALORIE_FLOOR = 1200.0;
    private const CALORIE_CEILING = 4500.0;

    /**
     * @return list<string> human-readable warnings (empty = nothing concerning)
     */
    public function __construct(
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
    ) {
    }

    public function validate(GoalDefinition $def): array
    {
        $warnings = [];

        if ($def->targetValue <= 0) {
            $warnings[] = $this->translator->trans('goal.warning.positive_target');
        }

        if (
            $def->direction === GoalDirection::Maintain
            && ($def->targetMax === null || $def->targetMax <= $def->targetValue)
        ) {
            $warnings[] = $this->translator->trans('goal.warning.maintain_range');
        }

        if ($def->evaluationWindowDays < 1 || $def->evaluationWindowDays > 90) {
            $warnings[] = $this->translator->trans('goal.warning.window_range');
        }

        $unit = $def->unit();
        $target = $def->targetValue;

        if ($def->metric === GoalMetric::Calories) {
            if ($def->direction === GoalDirection::AtMost && $target > 0 && $target < self::CALORIE_FLOOR) {
                $warnings[] = $this->translator->trans('goal.warning.calorie_floor', [
                    'target' => Format::number($target),
                    'floor'  => number_format(self::CALORIE_FLOOR),
                ]);
            }
            if ($def->direction === GoalDirection::AtLeast && $target > self::CALORIE_CEILING) {
                $warnings[] = $this->translator->trans('goal.warning.calorie_ceiling', [
                    'target' => Format::number($target),
                ]);
            }
        }

        // "Eat more" of the things people normally limit is rarely a wellness goal.
        if (
            $def->direction === GoalDirection::AtLeast
            && in_array($def->metric, [GoalMetric::Sugar, GoalMetric::SaturatedFat, GoalMetric::Sodium], true)
        ) {
            $warnings[] = $this->translator->trans('goal.warning.at_least_limit', [
                'target' => Format::number($target),
                'unit'   => $unit,
                'metric' => mb_strtolower($this->translator->trans($def->metric->transKey())),
            ]);
        }

        return $warnings;
    }
}

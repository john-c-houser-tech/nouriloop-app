<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\GoalDirection;
use App\Enum\GoalMetric;

/**
 * Reference daily values for the macro goal metrics — the macro counterpart to the
 * vitamin/mineral RDA map in SupplementRecommendationService. Used to ground goal
 * targets so a user who doesn't know "how much fiber is appropriate" gets a sane
 * starting number, and so the goal-setting conversation suggests grounded targets
 * rather than inventing them.
 *
 * Values are U.S. FDA Daily Values (2,000 kcal basis) / DRIs, on a daily-average
 * basis. This is general wellness reference information, not medical advice, and
 * individual needs vary.
 */
class DietaryReference
{
    /**
     * Reference for a metric: a typical daily target, the direction it's usually
     * framed as, and a short plain-language rationale. `value` is null for calories,
     * where an appropriate number depends on the individual (body size, activity).
     *
     * @return array{value: float|null, direction: GoalDirection|null, note: string}
     */
    public function for(GoalMetric $metric): array
    {
        return match ($metric) {
            GoalMetric::Calories => [
                'value'     => null,
                'direction' => null,
                'note'      => 'There is no one-size-fits-all calorie target — it depends on your '
                    . 'body size, activity, and goals. Most adults fall between 1,600 and 2,800 kcal/day.',
            ],
            GoalMetric::Protein => [
                'value'     => 50.0,
                'direction' => GoalDirection::AtLeast,
                'note'      => 'The Daily Value for protein is 50 g/day; active people often aim higher '
                    . '(roughly 1.2–1.6 g per kg of body weight).',
            ],
            GoalMetric::Carbohydrates => [
                'value'     => 275.0,
                'direction' => GoalDirection::AtMost,
                'note'      => 'The Daily Value for total carbohydrate is 275 g/day (2,000 kcal basis).',
            ],
            GoalMetric::Fat => [
                'value'     => 78.0,
                'direction' => GoalDirection::AtMost,
                'note'      => 'The Daily Value for total fat is 78 g/day (2,000 kcal basis).',
            ],
            GoalMetric::SaturatedFat => [
                'value'     => 20.0,
                'direction' => GoalDirection::AtMost,
                'note'      => 'The Daily Value for saturated fat is 20 g/day; diets lower in saturated '
                    . 'fat are generally encouraged.',
            ],
            GoalMetric::Fiber => [
                'value'     => 28.0,
                'direction' => GoalDirection::AtLeast,
                'note'      => 'The Daily Value for fiber is 28 g/day (about 14 g per 1,000 kcal). Most '
                    . 'adults get far less than this.',
            ],
            GoalMetric::Sugar => [
                'value'     => 50.0,
                'direction' => GoalDirection::AtMost,
                'note'      => 'The Daily Value for added sugars is 50 g/day; less is generally better.',
            ],
            GoalMetric::Sodium => [
                'value'     => 2300.0,
                'direction' => GoalDirection::AtMost,
                'note'      => 'The Daily Value for sodium is 2,300 mg/day.',
            ],
        };
    }

    /**
     * All references keyed by metric value — handy for seeding the UI / the
     * conversation system prompt in one shot.
     *
     * @return array<string, array{value: float|null, direction: GoalDirection|null, note: string}>
     */
    public function all(): array
    {
        $out = [];
        foreach (GoalMetric::cases() as $metric) {
            $out[$metric->value] = $this->for($metric);
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GoalDefinition;

/**
 * Produces the default evaluation prompt template for a goal. The template is
 * stored on the goal as editable data (prompt-as-data) and later filled in by the
 * evaluation service. Placeholders use {{double_braces}} and are substituted at
 * run time:
 *
 *   {{goal_name}}     the goal's name
 *   {{goal_summary}}  the structured definition as a sentence
 *   {{window_days}}   evaluation window length in days
 *   {{current_value}} the aggregated metric value over the window (with unit)
 *   {{target}}        the target (direction + value + unit)
 *   {{trend}}         comparison vs. the previous period
 *   {{status}}        deterministic outcome (achieved / on track / off track)
 *   {{meal_data}}     per-day metric values for the window
 *
 * The evaluation math is computed in PHP; Claude only writes the narrative, and
 * is told to stay descriptive and non-prescriptive (wellness feedback, not
 * medical advice).
 */
class GoalPromptBuilder
{
    public function defaultTemplate(GoalDefinition $def): string
    {
        // One logical line per sentence/instruction (no hard mid-sentence wraps) so
        // it soft-wraps cleanly in the editor textarea.
        // phpcs:disable Generic.Files.LineLength.TooLong -- prose lines are intentionally unwrapped
        return <<<'PROMPT'
        You are a supportive nutrition coach writing a short progress check-in for a NouriLoop user about one specific goal. Be warm, concrete, and encouraging.

        Goal: {{goal_name}}
        Definition: {{goal_summary}}
        Target: {{target}}
        Evaluation window: the last {{window_days}} days.

        Computed results (already calculated from the user's logged meals — treat these numbers as authoritative; do not recompute or contradict them):
        - Current value over the window: {{current_value}}
        - Target: {{target}}
        - Status: {{status}}
        - Trend vs. previous period: {{trend}}

        Per-day values:
        {{meal_data}}

        Write 2–4 short sentences that:
        1. State where the user stands relative to their target, using the numbers above.
        2. Note the trend (improving, holding, or slipping) versus the previous period.
        3. If the status is "achieved", celebrate it warmly.
        4. Offer one gentle, optional, non-prescriptive suggestion framed as general wellness information — never medical advice, diagnosis, or a prescriptive directive, and never suggest unsafe restriction.

        Do not invent data beyond what is provided. Do not use medical or clinical claims. Keep it friendly and plain-spoken.
        PROMPT;
        // phpcs:enable Generic.Files.LineLength.TooLong
    }
}

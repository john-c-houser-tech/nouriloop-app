<?php

declare(strict_types=1);

namespace App\Service;

use App\Ai\AiClient;
use App\Ai\AiFeature;
use App\Entity\User;
use App\Enum\GoalMetric;
use App\Support\Format;

/**
 * Drives the goal-setting conversation: Claude helps the user turn a vague
 * intention ("eat less sugar") into a measurable, structured goal. The system
 * prompt is grounded in the app's reference values and the user's own logged
 * baseline so suggested targets are sensible rather than invented, and Claude is
 * told to emit a structured proposal in a fenced ```goal-proposal block once it
 * has enough to converge on — which the controller turns into a real goal.
 */
class GoalConversationService
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly DietaryReference $reference,
        private readonly GoalMetricCalculator $calculator,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
        private readonly LocalePromptBuilder $localePrompt,
    ) {
    }

    /**
     * One conversational turn.
     *
     * @param list<array{role: string, content: string}> $history full transcript, ending with the latest user turn
     *
     * @return array{reply: string, proposal: array<string, mixed>|null, tokens: int}
     */
    public function converse(User $user, array $history): array
    {
        $result = $this->ai->complete(AiFeature::GoalSetup, $user, $this->systemPrompt($user), $history);

        return [
            'reply'    => self::stripProposalBlock($result->text),
            'proposal' => self::parseProposal($result->text),
            'tokens'   => $result->totalTokens(),
        ];
    }

    /**
     * The opening assistant message (no Claude call needed — keeps the first paint
     * instant and the first turn cheap).
     */
    public function greeting(): string
    {
        // Static opener (not part of the model transcript) — localized. The AI's
        // own replies stay English until Phase 3 wires locale into the prompt.
        return $this->translator->trans('goal.chat.greeting');
    }

    /**
     * Extracts the structured proposal from a reply, if present. Public + pure so
     * it can be unit-tested without an API call.
     *
     * @return array<string, mixed>|null
     */
    public static function parseProposal(string $text): ?array
    {
        if (!preg_match('/```goal-proposal\s*(.+?)```/s', $text, $m)) {
            return null;
        }

        $data = json_decode(trim($m[1]), true);

        return is_array($data) ? $data : null;
    }

    /** The visible chat text with the machine-readable proposal block removed. */
    public static function stripProposalBlock(string $text): string
    {
        return trim((string) preg_replace('/```goal-proposal\s*.+?```/s', '', $text));
    }

    private function systemPrompt(User $user): string
    {
        // One meal fetch covers every metric's baseline (not one query per metric).
        $baselines = $this->calculator->baselineMap($user, 30);

        $lines = [];
        foreach (GoalMetric::cases() as $metric) {
            $ref = $this->reference->for($metric);
            $baseline = $baselines[$metric->value];

            $refText = $ref['value'] !== null
                ? sprintf(
                    'reference ≈ %s %s (%s)',
                    Format::number($ref['value']),
                    $metric->unit(),
                    $ref['direction']?->label() ?? '',
                )
                : 'no fixed reference (depends on the person)';
            $baseText = $baseline !== null
                ? sprintf('user currently averages ~%s %s/day', Format::number($baseline), $metric->unit())
                : 'no recent data logged';

            $lines[] = sprintf('- %s (%s): %s; %s', $metric->label(), $metric->value, $refText, $baseText);
        }
        $context = implode("\n", $lines);

        return <<<PROMPT
        You are a friendly nutrition coach inside NouriLoop, helping the user define ONE measurable
        nutrition goal through a short conversation. This is general wellness guidance, not medical
        advice — never make medical or prescriptive claims, and never propose unsafe restriction
        (e.g. a calorie ceiling below 1,200 kcal/day).

        Trackable metrics, their reference values, and the user's own recent averages:
        $context

        Your job:
        1. Turn the user's intention into a measurable goal: pick the metric, a direction
           (at most / at least / a maintain range), a concrete target number, and an evaluation
           window (how many days of meals each check-in looks at).
        2. Ground the target in the reference value and the user's current average — suggest a
           realistic step from where they are, and say why. Ask at most one or two short
           clarifying questions if you genuinely need them; otherwise propose something concrete.
        3. Also settle the check-in schedule (daily or weekly + a day/time) and the output format
           (summary, chart, or report). Check-ins are delivered over the user's existing
           notification channel setting, so do not ask about a delivery channel.

        Keep replies short and conversational. When — and only when — you have enough to propose a
        complete goal AND the user seems ready to confirm, end your message with a fenced block
        EXACTLY like this (in addition to a one-line plain-language summary above it):

        ```goal-proposal
        {
          "name": "short goal name",
          "metric": "one of: calories|protein|carbohydrates|fat|saturated_fat|fiber|sugar|sodium",
          "direction": "at_most|at_least|maintain",
          "aggregation": "daily_average|window_total",
          "targetValue": number,
          "targetMax": number or null (only for maintain — the top of the range),
          "evaluationWindowDays": number,
          "frequency": "daily|weekly",
          "dayOfWeek": number 1-7 (Monday=1; only for weekly) or null,
          "hour": number 0-23,
          "minute": number 0-59,
          "outputFormat": "summary|chart|report",
          "rationale": "one sentence on why this target makes sense for them"
        }
        ```

        Do not emit the block until the goal is fully specified. Never include more than one block.
        PROMPT
        // Converse in the user's language; the proposal block's keys/enum values
        // stay exactly as specified (English identifiers), only prose localizes.
        // The fence-required sentence resolves the conflict with the locale
        // instruction's no-Markdown restatement (Copilot catch on PR #154).
        . ($this->localePrompt->forUser($user) !== ''
            ? "\n\n" . $this->localePrompt->forUser($user)
                . 'The fenced goal-proposal block is machine-read and REQUIRED when you propose a '
                . 'goal — emitting it is the single exception to the plain-prose/no-Markdown rule. '
                . 'Its keys and enum values must stay exactly as specified above; only the "name" '
                . 'and "rationale" values and your conversational prose are in the user\'s language.'
            : '');
    }
}

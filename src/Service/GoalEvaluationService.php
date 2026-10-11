<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GoalCheckin;
use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalCheckinStatus;
use App\Enum\GoalDirection;
use App\Service\Chart\GoalChartRenderer;
use App\Support\Format;
use Psr\Log\LoggerInterface;

/**
 * Evaluates one goal against the user's logged meals for its window and builds a
 * GoalCheckin: the progress numbers, the trend versus the previous period, and the
 * achieved/on-track/off-track status are all computed **deterministically in PHP**;
 * Claude is only asked to write the narrative around those numbers (so progress
 * claims can never be wrong, and the celebration state is reliable).
 *
 * The returned check-in is transient — the caller (the runner command) decides
 * whether to persist it and reschedule the goal.
 */
class GoalEvaluationService
{
    /**
     * The progress chart shows a trend, so it plots at least this many days of daily
     * values regardless of the (possibly 1-day) evaluation window — otherwise a daily
     * goal's chart is a single, useless point.
     */
    private const CHART_MIN_DAYS = 30;

    public function __construct(
        private readonly GoalMetricCalculator $calculator,
        private readonly ClaudeClient $claude,
        private readonly GoalChartRenderer $charts,
        private readonly LoggerInterface $logger,
        private readonly UserTime $userTime,
        private readonly TonePromptBuilder $tone,
        private readonly LocalePromptBuilder $localePrompt,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
    ) {
    }

    /**
     * @param bool $generateNarrative when false (dry-run), skips the Claude call and
     *                                token spend, using a deterministic summary
     */
    public function evaluate(NutritionGoal $goal, \DateTimeImmutable $now, bool $generateNarrative = true): GoalCheckin
    {
        $user = $goal->getUser();
        \assert($user instanceof User);
        $def = $goal->getDefinition();
        $unit = $def->unit();

        // Evaluate over the last N *complete* days: today is excluded (its partial day
        // would bias the average), and the previous window is the same length so the
        // trend comparison is fair. Boundaries are the user's local midnight so they
        // match the wall-clock stored on their meals.
        $to = $this->userTime->startOfToday($user, $now);
        $from = $to->modify('-' . $def->evaluationWindowDays . ' days');
        $daily = $this->calculator->dailyValues($user, $def->metric, $from, $to);
        $current = $this->calculator->aggregate($def->aggregation, $daily);

        $checkin = (new GoalCheckin())
            ->setGoal($goal)
            ->setRunAt($now)
            ->setWindowStart($from)
            ->setWindowEnd($to)
            ->setTargetValue($def->targetValue)
            ->setCurrentValue($current)
            ->setPromptVersion($goal->getPromptVersion());

        // ── Empty-data case: nothing to measure, no Claude call ──────────────
        if ($current === null) {
            // Stored + shown on the goal page — localized to the owner like the
            // deterministic fallback below.
            return $checkin
                ->setStatus(GoalCheckinStatus::NoData)
                ->setSummaryText($this->translator->trans('goal.checkin.no_data', [
                    'days' => $def->evaluationWindowDays,
                    'name' => $goal->getName(),
                ], null, $goal->getUser()?->getLocale()->value));
        }

        // ── Trend vs. the immediately preceding window ───────────────────────
        $prevFrom = $from->modify('-' . $def->evaluationWindowDays . ' days');
        $prevValue = $this->calculator->aggregate(
            $def->aggregation,
            $this->calculator->dailyValues($user, $def->metric, $prevFrom, $from),
        );
        [$trendKey, $trendText] = $this->trend(
            $def->direction,
            $prevValue,
            $current,
            $def->targetValue,
            $def->targetMax,
            $unit,
            $def->evaluationWindowDays,
        );

        $status = $this->status($def->isMet($current), $prevValue, $trendKey);
        $checkin->setStatus($status)->setTrend($trendKey);

        $summary = $generateNarrative
            ? $this->narrate($goal, $current, $status, $trendText, $trendKey, $prevValue, $daily, $checkin)
            : $this->deterministicSummary($goal, $current, $status, $trendKey, $prevValue);
        $checkin->setSummaryText($summary);

        // Chart for chart/report-format goals (skipped on dry-run alongside the narrative).
        if ($generateNarrative && $goal->getOutputFormat()->includesChart()) {
            // Plot a trend window (>= CHART_MIN_DAYS), not just the evaluation window —
            // and skip the chart entirely below 2 points so it's never a single dot.
            $chartDays = max($def->evaluationWindowDays, self::CHART_MIN_DAYS);
            $chartDaily = $chartDays === $def->evaluationWindowDays
                ? $daily
                : $this->calculator->dailyValues($user, $def->metric, $to->modify('-' . $chartDays . ' days'), $to);

            if (count($chartDaily) >= 2) {
                // Random stem — charts live in the web-served uploads dir, so the
                // name must not be guessable (was goal-id + timestamp, enumerable).
                $stem = 'goal-' . bin2hex(random_bytes(16));
                $checkin->setChartPath($this->charts->render($goal, $chartDaily, $stem));
            }
        }

        return $checkin;
    }

    /**
     * Decide the outcome from the numbers alone.
     */
    private function status(bool $isMet, ?float $prevValue, string $trendKey): GoalCheckinStatus
    {
        if ($isMet) {
            return GoalCheckinStatus::Achieved;
        }
        if ($prevValue === null) {
            return GoalCheckinStatus::OnTrack; // first check-in, give the benefit of the doubt
        }

        return $trendKey === 'improving' ? GoalCheckinStatus::OnTrack : GoalCheckinStatus::OffTrack;
    }

    /**
     * @return array{0: string, 1: string} [trendKey, human text]
     */
    private function trend(
        GoalDirection $direction,
        ?float $prev,
        float $current,
        float $target,
        ?float $max,
        string $unit,
        int $days,
    ): array {
        if ($prev === null) {
            return ['first', 'This is the first check-in, so there is no previous period to compare against.'];
        }

        $improvement = match ($direction) {
            GoalDirection::AtMost   => $prev - $current,
            GoalDirection::AtLeast  => $current - $prev,
            GoalDirection::Maintain => $this->distanceFromBand($prev, $target, $max)
                - $this->distanceFromBand($current, $target, $max),
        };

        $eps = max(0.5, 0.02 * max(abs($prev), abs($current)));
        $key = $improvement > $eps ? 'improving' : ($improvement < -$eps ? 'slipping' : 'holding');
        $verb = match ($key) {
            'improving' => 'improving',
            'slipping'  => 'slipping',
            default     => 'holding steady',
        };

        return [$key, sprintf(
            '%s — %s %s now vs. %s %s over the previous %d days.',
            ucfirst($verb),
            Format::number($current),
            $unit,
            Format::number($prev),
            $unit,
            $days,
        )];
    }

    private function distanceFromBand(float $value, float $low, ?float $high): float
    {
        $high ??= $low;
        if ($value < $low) {
            return $low - $value;
        }
        if ($value > $high) {
            return $value - $high;
        }

        return 0.0;
    }

    /**
     * Fills the goal's (user-editable) prompt template and asks Claude for prose.
     * On any API failure, falls back to a deterministic summary so a check-in is
     * never lost to an outage.
     *
     * @param array<string, float> $daily
     */
    private function narrate(
        NutritionGoal $goal,
        float $current,
        GoalCheckinStatus $status,
        string $trendText,
        string $trendKey,
        ?float $prevValue,
        array $daily,
        GoalCheckin $checkin,
    ): string {
        $def = $goal->getDefinition();
        $prompt = strtr($goal->getEvaluationPromptTemplate(), [
            '{{goal_name}}'     => $goal->getName(),
            '{{goal_summary}}'  => $def->summary(),
            '{{target}}'        => $this->targetText($def->direction, $def->targetValue, $def->targetMax, $def->unit()),
            '{{window_days}}'   => (string) $def->evaluationWindowDays,
            '{{current_value}}' => Format::number($current) . ' ' . $def->unit(),
            '{{status}}'        => $status->label(),
            '{{trend}}'         => $trendText,
            '{{meal_data}}'     => $this->mealDataLines($daily, $def->unit()),
        ]);

        try {
            $user = $goal->getUser();
            \assert($user instanceof User);
            $result = $this->claude->complete(
                'You are a supportive nutrition coach. Follow the instructions in the message exactly. '
                // Status glossary: without it, Claude reads "On track" with its everyday
                // meaning ("target met"), sees a current value short of the target, and
                // hedges about the "contradiction" in the narrative (hit in prod
                // 2026-07-23). Lives here — not in the per-goal prompt template — so it
                // applies to every existing goal without touching user-edited templates.
                . 'The status label uses a fixed vocabulary: "Goal achieved" means the target was met '
                . 'over this window. "On track" means the target was NOT met yet, but the trend is '
                . 'improving — or this is the first check-in with no previous period to compare — so a '
                . 'current value short of the target alongside an "On track" status is expected, not a '
                . 'contradiction. "Off track" means the target was not met and the trend is not '
                . 'improving. Never question, second-guess, or reinterpret the status or the numbers. '
                . 'Write in plain prose — no Markdown, asterisks, headings, or other formatting symbols. '
                . 'This is wellness feedback, not medical advice. '
                . $this->localePrompt->forUser($user)
                . $this->tone->forUser($user),
                [['role' => 'user', 'content' => $prompt]],
                $this->localePrompt->maxTokens($user->getLocale(), 512),
            );
            $checkin->setTokens($result->inputTokens, $result->outputTokens);

            $text = trim($result->text);
            if ($text !== '') {
                return $text;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Goal evaluation narrative failed: {error}', ['error' => $e->getMessage()]);
        }

        return $this->deterministicSummary($goal, $current, $status, $trendKey, $prevValue);
    }

    /**
     * Claude-outage fallback, composed in the OWNER's locale — the cron runs
     * before GoalDeliveryDispatcher's runWithLocale wrap, so the stored summary
     * must already be localized (Copilot catch on PR #154). Built from the
     * trend *key* (not the English prompt sentence); ICU localizes the raw
     * numeric params, and targetText() is language-neutral (numbers + unit).
     */
    private function deterministicSummary(
        NutritionGoal $goal,
        float $current,
        GoalCheckinStatus $status,
        string $trendKey,
        ?float $prevValue,
    ): string {
        $def = $goal->getDefinition();
        $locale = $goal->getUser()?->getLocale()->value;

        $trend = $trendKey === 'first' || $prevValue === null
            ? $this->translator->trans('goal.checkin.trend_first', [], null, $locale)
            : $this->translator->trans('goal.checkin.trend_' . $trendKey, [
                'current' => $current,
                'prev'    => $prevValue,
                'unit'    => $def->unit(),
                'days'    => $def->evaluationWindowDays,
            ], null, $locale);

        return $this->translator->trans('goal.checkin.fallback', [
            'status'  => $this->translator->trans($status->transKey(), [], null, $locale),
            'current' => $current,
            'unit'    => $def->unit(),
            'target'  => $this->targetText($def->direction, $def->targetValue, $def->targetMax, $def->unit()),
            'trend'   => $trend,
        ], null, $locale);
    }

    private function targetText(GoalDirection $direction, float $target, ?float $max, string $unit): string
    {
        if ($direction === GoalDirection::Maintain && $max !== null) {
            return sprintf('%s–%s %s', Format::number($target), Format::number($max), $unit);
        }

        return sprintf('%s %s %s', $direction->label(), Format::number($target), $unit);
    }

    /**
     * @param array<string, float> $daily
     */
    private function mealDataLines(array $daily, string $unit): string
    {
        if ($daily === []) {
            return '(no days with logged meals)';
        }

        $lines = [];
        foreach ($daily as $date => $value) {
            $day = (new \DateTimeImmutable($date))->format('D, M j');
            $lines[] = sprintf('- %s: %s %s', $day, Format::number($value), $unit);
        }

        return implode("\n", $lines);
    }
}

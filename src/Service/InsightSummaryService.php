<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\HealthMetricType;
use App\Enum\UnitSystem;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reduces the Insights page's raw chart data into compact dashboard "tiles",
 * grouped by the three narrative themes. Each tile carries a headline stat and
 * an attention badge (ok / watch / alert / muted) so the overview can surface
 * what's worth a closer look before the user drills into a chart.
 *
 * All thresholds here are tunable, deliberately non-medical wellness heuristics
 * (consistent with the rest of the app's framing) and mirror the values already
 * used by NotificationService / the trends template — no service is refactored.
 *
 * Adding a future insight = add a card builder here + a tile + a detail panel +
 * an init function in the template. Nothing else.
 */
class InsightSummaryService
{
    public const OK    = 'ok';
    public const WATCH = 'watch';
    public const ALERT = 'alert';
    public const MUTED = 'muted';

    // Mirrors NotificationService::CAL_MIN / CAL_MAX.
    private const CAL_MIN = 1200.0;
    private const CAL_MAX = 3500.0;
    // Mirrors NotificationService::STALE_DAYS.
    private const STALE_DAYS = 7;

    private const RDA_ALERT = 40.0; // a gap this far below RDA escalates the tile to alert

    public function __construct(
        private readonly UnitConverter $units,
        private readonly TranslatorInterface $translator,
        private readonly LocaleFormatter $localeFormatter,
    ) {
    }

    /**
     * @param array{
     *   inflammationDaily: list<array{date: string, score: float, band: string}>,
     *   sleepData: list<array<string, mixed>>,
     *   healthAggByType: array<string, list<array<string, mixed>>>,
     *   metricMeta: array<string, array{label: string, unit: string}>,
     *   calorieTotals: list<array{date: string, calories: mixed}>,
     *   averages: array<string, mixed>,
     *   microGaps: list<array{pct: float, ...}>,
     *   days: int,
     *   symptomSeriesByType: array<int|string, array<string, mixed>>,
     *   today: \DateTimeImmutable
     * } $ctx
     *
     * @return list<array{theme: string, title: string, cards: list<array<string, mixed>>}>
     */
    public function build(array $ctx): array
    {
        return [
            [
                'theme' => 'consequences',
                'title' => $this->translator->trans('insight.theme.consequences'),
                'cards' => array_values(array_filter(array_merge(
                    [$this->inflammationCard($ctx['inflammationDaily'] ?? [])],
                    $this->symptomCards($ctx['symptomSeriesByType'] ?? []),
                    [$this->sleepCard($ctx['sleepData'] ?? [])],
                ))),
            ],
            [
                'theme' => 'body',
                'title' => $this->translator->trans('insight.theme.body'),
                'cards' => $this->healthCards(
                    $ctx['healthAggByType'] ?? [],
                    $ctx['metricMeta'] ?? [],
                    $ctx['today'],
                    $ctx['unitSystem'] ?? UnitSystem::Metric,
                ),
            ],
            [
                'theme' => 'intake',
                'title' => $this->translator->trans('insight.theme.intake'),
                'cards' => array_values(array_filter([
                    $this->caloriesCard($ctx['calorieTotals'] ?? []),
                    $this->macrosCard($ctx['averages'] ?? [], $ctx['calorieTotals'] ?? []),
                    $this->microsCard($ctx['microGaps'] ?? [], $ctx['calorieTotals'] ?? [], (int) ($ctx['days'] ?? 7)),
                ])),
            ],
        ];
    }

    /** @param list<array{date: string, score: float, band: string}> $daily */
    private function inflammationCard(array $daily): array
    {
        if ($daily === []) {
            return $this->muted(
                'inflammation',
                'inflammation',
                $this->translator->trans('insight.tile.inflammation'),
                $this->translator->trans('insight.note.log_meals'),
            );
        }

        usort($daily, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
        $latest = end($daily);
        $score  = (float) $latest['score'];
        $band   = InflammationScoreService::band($score);

        $status = match ($band) {
            'strong-pro', 'mild-pro' => self::ALERT,
            'neutral'                => self::WATCH,
            default                  => self::OK,
        };
        $note = $this->translator->trans('insight.note.band_' . str_replace('-', '_', $band));

        $headline = sprintf('%+.1f', $score);

        $card = $this->card(
            'inflammation',
            'inflammation',
            $this->translator->trans('insight.tile.inflammation'),
            $headline,
            '',
            $status,
            $note,
        );
        $card['trend'] = $this->trend(array_map(static fn(array $r): float => (float) $r['score'], $daily));

        return $card;
    }

    /** @param list<array<string, mixed>> $sleep */
    private function sleepCard(array $sleep): array
    {
        if ($sleep === []) {
            return $this->muted(
                'sleep',
                'sleep',
                $this->translator->trans('insight.tile.sleep'),
                $this->translator->trans('insight.note.no_sleep'),
            );
        }

        usort($sleep, static fn(array $a, array $b): int => $a['sleep_date'] <=> $b['sleep_date']);
        $hours = array_map(static fn(array $n): float => (float) $n['total_hours'], $sleep);
        $avg   = array_sum($hours) / count($hours);

        $status = $avg < 6.0 ? self::ALERT : ($avg < 7.0 ? self::WATCH : self::OK);
        $note   = $status === self::OK
            ? $this->translator->trans('insight.note.on_track')
            : $this->translator->trans('insight.note.below_sleep_target');

        $card = $this->card(
            'sleep',
            'sleep',
            $this->translator->trans('insight.tile.sleep'),
            $this->localeFormatter->number($avg, 1),
            $this->translator->trans('insight.unit.hr_avg'),
            $status,
            $note,
        );
        $card['trend'] = $this->trend($hours);

        return $card;
    }

    /**
     * One tile per enabled symptom, each drilling into its **own** per-symptom
     * relationships panel (`symptom-<id>`). Enabled-but-unlogged symptoms show muted.
     *
     * @param array<int|string, array{label?: string, higherIsBetter?: bool, data?: array<string, mixed>}> $byType
     *
     * @return list<array<string, mixed>>
     */
    private function symptomCards(array $byType): array
    {
        $cards = [];
        foreach ($byType as $typeId => $s) {
            $title = $s['label'] ?? $this->translator->trans('insight.tile.symptom');
            $panel = 'symptom-' . $typeId;
            $data  = $s['data'] ?? [];

            if ($data === []) {
                // No panel without logged data, so a muted, non-clickable tile.
                $cards[] = $this->muted($panel, $panel, $title, $this->translator->trans('insight.note.no_checkins'));
                continue;
            }

            ksort($data);
            $level = (float) end($data);
            [$status, $note] = $this->symptomStatus($level, (bool) ($s['higherIsBetter'] ?? false));

            $card = $this->card(
                $panel,
                $panel,
                $title,
                (string) (int) round($level),
                $this->translator->trans('insight.unit.out_of_10'),
                $status,
                $note,
            );
            $card['trend'] = $this->trend(array_map('floatval', array_values($data)));
            $cards[] = $card;
        }

        return $cards;
    }

    /**
     * @return array{0: string, 1: string} status + note for a 0–10 symptom level.
     *   For "higher is better" symptoms (energy, acuity) the polarity flips.
     */
    private function symptomStatus(float $level, bool $higherIsBetter): array
    {
        if ($higherIsBetter) {
            return $level <= 3
                ? [self::ALERT, $this->translator->trans('insight.note.low')]
                : ($level <= 6
                    ? [self::WATCH, $this->translator->trans('insight.note.moderate')]
                    : [self::OK, $this->translator->trans('insight.note.good')]);
        }

        return $level >= 7
            ? [self::ALERT, $this->translator->trans('insight.note.high')]
            : ($level >= 4
                ? [self::WATCH, $this->translator->trans('insight.note.moderate')]
                : [self::OK, $this->translator->trans('insight.note.low')]);
    }

    /**
     * One tile per available metric type; all drill into the shared health chart,
     * focused on their own metric.
     *
     * @param array<string, list<array<string, mixed>>> $aggByType
     * @param array<string, array{label: string, unit: string}> $meta
     *
     * @return list<array<string, mixed>>
     */
    private function healthCards(
        array $aggByType,
        array $meta,
        \DateTimeImmutable $today,
        UnitSystem $unitSystem,
    ): array {
        $cards = [];
        foreach ($aggByType as $type => $series) {
            if ($series === []) {
                continue;
            }
            usort($series, static fn(array $a, array $b): int => strcmp((string) $a['bucket'], (string) $b['bucket']));
            $latest    = end($series);
            $value     = (float) $latest['avg'];
            $label     = $meta[$type]['label'] ?? $type;
            $unit      = $meta[$type]['unit'] ?? '';
            $headline  = $this->formatMetric($type, $value);

            $trend = $this->trend(array_map(static fn(array $d): float => (float) $d['avg'], $series));

            // Steps have no absolute target — more is better — so judge the tile by
            // direction; everything else uses its absolute-threshold status.
            [$status, $note] = $type === HealthMetricType::Steps->value
                ? $this->stepsStatus($trend)
                : $this->metricStatus($type, $value, $unitSystem);

            // Staleness only downgrades an otherwise-ok reading.
            $ageDays = $this->ageInDays((string) $latest['bucket'], $today);
            if ($ageDays !== null && $ageDays > self::STALE_DAYS && $status === self::OK) {
                $status = self::WATCH;
                $note   = $this->translator->trans('insight.note.days_ago', ['count' => $ageDays]);
            }

            $card = $this->card('health-' . $type, 'health', $label, $headline, $unit, $status, $note);
            $card['metric'] = $type; // tells the panel which series to focus
            $card['trend']  = $trend;
            $cards[] = $card;
        }

        return $cards;
    }

    /**
     * Steps have no absolute "bad" threshold, so direction is the signal: more is
     * better → green when rising, red when falling, neutral when flat/unknown.
     *
     * @return array{0: string, 1: string} status + note
     */
    private function stepsStatus(?string $trend): array
    {
        return match ($trend) {
            'up'    => [self::OK, $this->translator->trans('insight.note.trending_up')],
            'down'  => [self::ALERT, $this->translator->trans('insight.note.trending_down')],
            'flat'  => [self::OK, $this->translator->trans('insight.note.holding_steady')],
            default => [self::OK, $this->translator->trans('insight.note.latest')], // null → not enough data to tell
        };
    }

    /**
     * @param float $v reading in the user's DISPLAY units (already converted)
     *
     * @return array{0: string, 1: string} status + note for a metric reading
     */
    private function metricStatus(string $type, float $v, UnitSystem $unitSystem): array
    {
        // Glucose cutoffs are clinical mg/dL values; express them in the display
        // unit so the comparison is correct whether the user sees mg/dL or mmol/L.
        $bgHigh = $this->units->toDisplay(HealthMetricType::BloodGlucose, 180.0, $unitSystem);
        $bgElev = $this->units->toDisplay(HealthMetricType::BloodGlucose, 140.0, $unitSystem);

        return match ($type) {
            HealthMetricType::BloodGlucose->value => $v >= $bgHigh
                ? [self::ALERT, $this->translator->trans('insight.note.high')]
                : ($v >= $bgElev
                    ? [self::WATCH, $this->translator->trans('insight.note.elevated')]
                    : [self::OK, $this->translator->trans('insight.note.in_range')]),
            HealthMetricType::BloodPressureSystolic->value => $v >= 140
                ? [self::ALERT, $this->translator->trans('insight.note.high')]
                : ($v >= 130
                    ? [self::WATCH, $this->translator->trans('insight.note.elevated')]
                    : [self::OK, $this->translator->trans('insight.note.in_range')]),
            HealthMetricType::BloodPressureDiastolic->value => $v >= 90
                ? [self::ALERT, $this->translator->trans('insight.note.high')]
                : ($v >= 80
                    ? [self::WATCH, $this->translator->trans('insight.note.elevated')]
                    : [self::OK, $this->translator->trans('insight.note.in_range')]),
            HealthMetricType::HeartRate->value => $v >= 100
                ? [self::WATCH, $this->translator->trans('insight.note.elevated')]
                : [self::OK, $this->translator->trans('insight.note.latest')],
            default => [self::OK, $this->translator->trans('insight.note.latest')],
        };
    }

    private function formatMetric(string $type, float $v): string
    {
        return match ($type) {
            HealthMetricType::Weight->value,
            HealthMetricType::BodyFatPercentage->value => $this->localeFormatter->number($v, 1),
            HealthMetricType::Steps->value             => $this->localeFormatter->number($v),
            default                                    => $this->localeFormatter->number(round($v)),
        };
    }

    /** @param list<array{date: string, calories: mixed}> $totals */
    private function caloriesCard(array $totals): array
    {
        if ($totals === []) {
            return $this->muted(
                'calories',
                'calories',
                $this->translator->trans('insight.tile.calories'),
                $this->translator->trans('insight.note.log_meals'),
            );
        }

        usort($totals, static fn(array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));
        $vals = array_map(static fn(array $r): float => (float) $r['calories'], $totals);
        $avg  = array_sum($vals) / count($vals);

        $status = ($avg < self::CAL_MIN || $avg > self::CAL_MAX) ? self::ALERT : self::OK;
        $note = match (true) {
            $avg > self::CAL_MAX => $this->translator->trans('insight.note.above_range'),
            $avg < self::CAL_MIN => $this->translator->trans('insight.note.below_range'),
            default              => $this->translator->trans('insight.note.in_range'),
        };

        $card = $this->card(
            'calories',
            'calories',
            $this->translator->trans('insight.tile.calories'),
            $this->localeFormatter->number($avg),
            $this->translator->trans('insight.unit.kcal_avg'),
            $status,
            $note,
        );
        $card['trend'] = $this->trend($vals);

        return $card;
    }

    /**
     * @param array<string, mixed> $avgs
     * @param list<array<string, mixed>> $totals
     */
    private function macrosCard(array $avgs, array $totals): array
    {
        if ($totals === []) {
            return $this->muted(
                'macros',
                'macros',
                $this->translator->trans('insight.tile.macros'),
                $this->translator->trans('insight.note.log_meals'),
            );
        }

        $p = (int) round((float) ($avgs['protein'] ?? 0));
        $c = (int) round((float) ($avgs['carbohydrates'] ?? 0));
        $f = (int) round((float) ($avgs['fat'] ?? 0));

        return $this->card(
            'macros',
            'macros',
            $this->translator->trans('insight.tile.macros'),
            "{$p} / {$c} / {$f}",
            $this->translator->trans('insight.unit.g_pcf'),
            self::OK,
            $this->translator->trans('insight.note.daily_average'),
        );
    }

    /**
     * Micronutrient adequacy, driven by the SAME supplement-inclusive gap list the
     * Supplements page uses (SupplementRecommendationService::getRecommendations) —
     * so the tile can't disagree with that page. Unlike the other intake tiles it
     * links to /supplements (the richer, supplement-aware destination with per-
     * nutrient suggestions) rather than opening an on-page chart.
     *
     * @param list<array{pct: float, ...}> $gaps  nutrients below 75% RDA (supplements
     *     folded in); each gap carries more keys (label/rda/…) — only `pct` is read here
     * @param list<array<string, mixed>> $totals  used only as a "has any meals" signal
     * @param int $days  the window the gaps were computed over; reused as the link
     *                   window (both pages share 7/14/30/90) so the two can't disagree
     */
    private function microsCard(array $gaps, array $totals, int $days): array
    {
        if ($totals === []) {
            // Mirrors the calories/macros tiles: nothing logged → nothing to read.
            return $this->muted(
                'micros',
                'micros',
                $this->translator->trans('insight.tile.micros'),
                $this->translator->trans('insight.note.log_meals'),
            );
        }

        $below = count($gaps);
        $worst = self::OK;
        foreach ($gaps as $gap) {
            if ((float) $gap['pct'] < self::RDA_ALERT) {
                $worst = self::ALERT;
                break;
            }
            $worst = self::WATCH;
        }

        // At zero gaps the "below target" unit would read "0 below target" — drop it
        // so the tile reads just "0 · all at target".
        $note = $below === 0
            ? $this->translator->trans('insight.note.all_at_target')
            : $this->translator->trans('insight.note.below_rda');
        $unit = $below === 0 ? '' : $this->translator->trans('insight.unit.below_target');
        $card = $this->card(
            'micros',
            'micros',
            $this->translator->trans('insight.tile.micros'),
            (string) $below,
            $unit,
            $worst,
            $note,
        );

        // Link to the Supplements page instead of an on-page panel, over the exact
        // window the count was computed over — both pages offer 7/14/30/90, so the
        // tile count and the page always match.
        $card['linkRoute']  = 'app_supplements';
        $card['linkParams'] = ['days' => $days];

        return $card;
    }

    /**
     * @return array{key: string, panel: string, title: string, headline: string,
     *   unit: string, status: string, note: string, available: bool}
     */
    private function card(
        string $key,
        string $panel,
        string $title,
        string $headline,
        string $unit,
        string $status,
        string $note,
    ): array {
        return [
            'key'       => $key,
            'panel'     => $panel,
            'title'     => $title,
            'headline'  => $headline,
            'unit'      => $unit,
            'status'    => $status,
            'note'      => $note,
            'available' => true,
        ];
    }

    private function muted(string $key, string $panel, string $title, string $note): array
    {
        return [
            'key'       => $key,
            'panel'     => $panel,
            'title'     => $title,
            'headline'  => '—',
            'unit'      => '',
            'status'    => self::MUTED,
            'note'      => $note,
            'available' => false,
        ];
    }

    /**
     * Direction the series has been heading: compares the mean of its later half
     * against its earlier half, judged "flat" unless the shift exceeds 10% of the
     * series' own scale — taken as the larger of its typical level and its range,
     * so the threshold adapts to each metric (a 2,000 kcal series and an
     * inflammation score swinging around zero are both handled sanely). Returns
     * 'up' | 'down' | 'flat', or null when there aren't enough points to tell.
     * Polarity-neutral on purpose — it shows direction; the badge shows good/bad.
     *
     * @param list<float> $values oldest → newest
     */
    private function trend(array $values): ?string
    {
        $values = array_values(array_filter($values, static fn($v): bool => $v !== null));
        $n = count($values);
        if ($n < 4) {
            return null;
        }

        $half        = intdiv($n, 2);
        $earlierMean = array_sum(array_slice($values, 0, $half)) / $half;
        $laterMean   = array_sum(array_slice($values, $n - $half)) / $half;
        $diff        = $laterMean - $earlierMean;

        $level = (abs($earlierMean) + abs($laterMean)) / 2;
        $scale = max($level, max($values) - min($values));
        if ($scale <= 0.0 || abs($diff) < 0.10 * $scale) {
            return 'flat';
        }

        return $diff > 0 ? 'up' : 'down';
    }

    private function ageInDays(string $bucket, \DateTimeImmutable $today): ?int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($bucket, 0, 10));
        if ($date === false) {
            return null;
        }

        return (int) $date->diff($today->setTime(0, 0))->format('%a');
    }
}

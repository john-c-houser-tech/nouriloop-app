<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PostprandialResponse;
use App\Entity\MealEntry;
use App\Repository\HealthMetricRepository;

/**
 * Computes a meal's postprandial glucose response from CGM/blood-glucose readings —
 * the curve in the window following the meal plus its summary metrics (baseline,
 * peak, delta, incremental AUC, return-to-baseline).
 *
 * Pure, deterministic arithmetic over readings the user already has (no Claude call,
 * no persistence): the result is recomputed at view time, so it naturally improves as
 * CGM data back-fills after the meal — there is nothing to invalidate. Window sizes
 * and the curve threshold are documented, tunable constants (cf. InflammationScoreService).
 *
 * Meal `loggedAt` and glucose `recorded_at` share the user's wall-clock frame, so the
 * window is a plain time arithmetic with no timezone conversion. The result is an
 * educational wellness signal, not a medical measure.
 */
class PostprandialResponseService
{
    /** Minutes before the meal used to establish the pre-meal baseline. */
    public const BASELINE_WINDOW_MIN = 15;

    /** Minutes after the meal over which the response is measured (~standard 2–3 h). */
    public const RESPONSE_WINDOW_MIN = 180;

    /** Minimum post-meal readings needed before a response curve is meaningful. */
    public const MIN_READINGS_FOR_CURVE = 6;

    /** Post-meal readings (with a baseline) above which confidence is "high". */
    private const HIGH_CONFIDENCE_READINGS = 12;

    /** mg/dL tolerance for treating glucose as "back to baseline". */
    private const RETURN_EPSILON = 5.0;

    public function __construct(
        private readonly HealthMetricRepository $metrics,
    ) {
    }

    /**
     * The meal's glucose response, or null when the user has no glucose readings at
     * all in the window (nothing to show). A response with too few post-meal readings
     * is still returned, with hasCurve = false, so the UI can show a "limited data" note.
     */
    public function forMeal(MealEntry $meal): ?PostprandialResponse
    {
        $user = $meal->getUser();
        if ($user === null) {
            return null;
        }

        [$from, $to] = self::window($meal);

        return $this->fromRows($meal, $this->metrics->findGlucoseInWindow($user, $from, $to));
    }

    /**
     * {@see forMeal()} for many meals with ONE glucose query per user instead of
     * one per meal (~360 queries on a 90-day insights window). Fetches the span
     * covering every meal's window, then hands each meal exactly the rows its
     * own query would have returned — same inclusive bounds, compared as the
     * same 'Y-m-d H:i:s' strings the SQL compares — so results are identical.
     *
     * @param list<MealEntry> $meals
     *
     * @return array<int, ?PostprandialResponse> keyed like $meals
     */
    public function forMeals(array $meals): array
    {
        $out = [];
        /** @var array<int, array{user: \App\Entity\User, keys: list<int>}> $byUser */
        $byUser = [];
        foreach ($meals as $key => $meal) {
            $user = $meal->getUser();
            if ($user === null) {
                $out[$key] = null;
                continue;
            }
            $byUser[spl_object_id($user)] ??= ['user' => $user, 'keys' => []];
            $byUser[spl_object_id($user)]['keys'][] = $key;
        }

        foreach ($byUser as ['user' => $user, 'keys' => $keys]) {
            // Span bounds are the meals' own window objects (picked by the same
            // string the SQL compares), never re-parsed from a string — a wall-clock
            // string inside a DST gap would re-parse an hour off.
            $windows = [];
            $spanFrom = $spanTo = null;
            foreach ($keys as $key) {
                [$from, $to] = self::window($meals[$key]);
                $windows[$key] = [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
                if ($spanFrom === null || strcmp($windows[$key][0], $spanFrom->format('Y-m-d H:i:s')) < 0) {
                    $spanFrom = $from;
                }
                if ($spanTo === null || strcmp($windows[$key][1], $spanTo->format('Y-m-d H:i:s')) > 0) {
                    $spanTo = $to;
                }
            }
            \assert($spanFrom !== null && $spanTo !== null);

            $rows = $this->metrics->findGlucoseInWindow($user, $spanFrom, $spanTo);

            foreach ($windows as $key => [$from, $to]) {
                $out[$key] = $this->fromRows($meals[$key], self::slice($rows, $from, $to));
            }
        }

        ksort($out);

        return $out;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} the meal's [from, to] glucose window */
    private static function window(MealEntry $meal): array
    {
        $mealAt = $meal->getLoggedAt();

        return [
            $mealAt->sub(new \DateInterval('PT' . self::BASELINE_WINDOW_MIN . 'M')),
            $mealAt->add(new \DateInterval('PT' . self::RESPONSE_WINDOW_MIN . 'M')),
        ];
    }

    /**
     * Rows with $from <= recordedAt <= $to, from rows sorted by recordedAt —
     * binary search for the first, then walk until past $to.
     *
     * @param list<array{recordedAt: string, value: mixed}> $rows
     *
     * @return list<array{recordedAt: string, value: mixed}>
     */
    private static function slice(array $rows, string $from, string $to): array
    {
        $lo = 0;
        $hi = count($rows);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if (strcmp((string) $rows[$mid]['recordedAt'], $from) < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        $slice = [];
        for ($i = $lo, $n = count($rows); $i < $n && strcmp((string) $rows[$i]['recordedAt'], $to) <= 0; $i++) {
            $slice[] = $rows[$i];
        }

        return $slice;
    }

    /**
     * The response computed from the meal's glucose rows (sorted by time), or null
     * when there are none.
     *
     * @param list<array{recordedAt: string, value: mixed}> $rows
     */
    private function fromRows(MealEntry $meal, array $rows): ?PostprandialResponse
    {
        if ($rows === []) {
            return null;
        }

        $mealTs = $meal->getLoggedAt()->getTimestamp();

        /** @var list<array{minutes: int, value: float}> $series */
        $series = [];
        /** @var list<float> $pre */
        $pre = [];
        /** @var list<array{minutes: int, value: float}> $post */
        $post = [];

        foreach ($rows as $row) {
            // Split pre/post on the raw second-level offset so a reading just before
            // the meal isn't rounded up to minute 0 and miscounted as post-meal. The
            // displayed minute bucket floors that same offset, so its sign always
            // matches the side it landed on (pre = negative, post = ≥ 0).
            $deltaSec = (new \DateTimeImmutable($row['recordedAt']))->getTimestamp() - $mealTs;
            $minutes  = (int) floor($deltaSec / 60);
            $value    = (float) $row['value'];

            $series[] = ['minutes' => $minutes, 'value' => $value];
            if ($deltaSec < 0) {
                $pre[] = $value;
            } else {
                // The reading at the meal time starts the response, not the baseline.
                $post[] = ['minutes' => $minutes, 'value' => $value];
            }
        }

        $hasBaseline = $pre !== [];
        // Baseline: mean of pre-meal readings, else fall back to the first reading at/after the meal.
        $baseline = $hasBaseline
            ? array_sum($pre) / count($pre)
            : ($post[0]['value'] ?? null);

        $postCount = count($post);
        $hasCurve  = $postCount >= self::MIN_READINGS_FOR_CURVE;

        $peak = $timeToPeak = $delta = $iauc = $returnToBaseline = null;

        if ($post !== [] && $baseline !== null) {
            foreach ($post as $point) {
                if ($peak === null || $point['value'] > $peak) {
                    $peak       = $point['value'];
                    $timeToPeak = $point['minutes'];
                }
            }

            $delta = round($peak - $baseline, 1);

            // Incremental AUC: trapezoidal area between the curve and the baseline,
            // counting only excursions above baseline (mg/dL·min).
            $area = 0.0;
            for ($i = 1, $n = count($post); $i < $n; $i++) {
                $width = $post[$i]['minutes'] - $post[$i - 1]['minutes'];
                $a     = max(0.0, $post[$i - 1]['value'] - $baseline);
                $b     = max(0.0, $post[$i]['value'] - $baseline);
                $area += (($a + $b) / 2) * $width;
            }
            $iauc = round($area);

            // First reading after the peak that has settled back to baseline.
            foreach ($post as $point) {
                if ($point['minutes'] > $timeToPeak && $point['value'] <= $baseline + self::RETURN_EPSILON) {
                    $returnToBaseline = $point['minutes'];
                    break;
                }
            }
        }

        $confidence = match (true) {
            $hasCurve && $hasBaseline && $postCount >= self::HIGH_CONFIDENCE_READINGS => 'high',
            $hasCurve => 'moderate',
            default   => 'low',
        };

        return new PostprandialResponse(
            series: $series,
            baseline: $baseline !== null ? round($baseline, 1) : null,
            peak: $peak,
            timeToPeakMin: $timeToPeak,
            delta: $delta,
            iauc: $iauc,
            returnToBaselineMin: $returnToBaseline,
            readingCount: count($series),
            hasBaseline: $hasBaseline,
            hasCurve: $hasCurve,
            confidence: $confidence,
        );
    }
}

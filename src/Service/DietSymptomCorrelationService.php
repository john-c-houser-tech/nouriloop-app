<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Relates the daily dietary inflammation score to self-reported joint pain,
 * both same-day and next-day (inflammation may take a day to manifest). Pure
 * arithmetic over two date-keyed series — no persistence — so it is easy to test
 * and reuse. Results are descriptive, not causal: it reports averages and a
 * Pearson correlation, leaving interpretation (and small-sample caveats) to the UI.
 */
class DietSymptomCorrelationService
{
    /** A day counts as pro-inflammatory at or above this score. */
    public const PRO_THRESHOLD = 1.0;

    /**
     * @param  array<string, float> $scoreByDate inflammation score keyed by 'Y-m-d'
     * @param  array<string, int>   $painByDate  joint pain (0–10) keyed by 'Y-m-d'
     * @return array{
     *     sameDay: array{n:int, r:float|null, proAvgPain:float|null, otherAvgPain:float|null},
     *     nextDay: array{n:int, r:float|null, proAvgPain:float|null, otherAvgPain:float|null},
     *     threshold: float
     * }
     */
    public function summarize(array $scoreByDate, array $painByDate): array
    {
        return [
            'sameDay'   => $this->pair($scoreByDate, $painByDate, 0),
            'nextDay'   => $this->pair($scoreByDate, $painByDate, 1),
            'threshold' => self::PRO_THRESHOLD,
        ];
    }

    /**
     * Builds (score, pain) pairs where pain is read $lagDays after the score's
     * date, then summarises them.
     *
     * @param  array<string, float> $scoreByDate
     * @param  array<string, int>   $painByDate
     * @return array{n:int, r:float|null, proAvgPain:float|null, otherAvgPain:float|null}
     */
    private function pair(array $scoreByDate, array $painByDate, int $lagDays): array
    {
        $scores = [];
        $pains  = [];
        $proPain   = [];
        $otherPain = [];

        foreach ($scoreByDate as $date => $score) {
            $painDate = $lagDays === 0
                ? $date
                : (new \DateTimeImmutable($date))->modify("+{$lagDays} day")->format('Y-m-d');

            if (!array_key_exists($painDate, $painByDate)) {
                continue;
            }

            $pain     = $painByDate[$painDate];
            $scores[] = $score;
            $pains[]  = (float) $pain;

            if ($score >= self::PRO_THRESHOLD) {
                $proPain[] = $pain;
            } else {
                $otherPain[] = $pain;
            }
        }

        return [
            'n'            => count($scores),
            'r'            => $this->pearson($scores, $pains),
            'proAvgPain'   => $this->avg($proPain),
            'otherAvgPain' => $this->avg($otherPain),
        ];
    }

    /**
     * @param  array<int|float> $values
     */
    private function avg(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 1);
    }

    /**
     * Pearson correlation, or null when undefined (n < 2 or no variance).
     *
     * @param list<float> $x
     * @param list<float> $y
     */
    private function pearson(array $x, array $y): ?float
    {
        $n = count($x);
        if ($n < 2) {
            return null;
        }

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $cov = 0.0;
        $varX = 0.0;
        $varY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $cov += $dx * $dy;
            $varX += $dx * $dx;
            $varY += $dy * $dy;
        }

        if ($varX <= 0.0 || $varY <= 0.0) {
            return null;
        }

        return round($cov / sqrt($varX * $varY), 2);
    }
}

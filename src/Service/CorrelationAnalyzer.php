<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CorrelationResult;

/**
 * Correlates a symptom's daily levels against arbitrary daily factor series
 * (diet macros, inflammation, health metrics) and reports only the statistically
 * significant relationships. Pure arithmetic over date-keyed maps — no persistence.
 *
 * Significance is a two-tailed Student-t test on the Pearson coefficient
 * (t = r·√((n−2)/(1−r²)), p from the regularized incomplete beta function), so a
 * relationship is surfaced only when n ≥ MIN_N paired days AND p < ALPHA. Factors
 * are tested both same-day and next-day (diet/sleep on day D vs the symptom on day
 * D+lag), since some effects lag a day. Descriptive, not causal.
 */
final class CorrelationAnalyzer
{
    /** Minimum paired days before a correlation is trustworthy enough to show. */
    public const MIN_N = 7;

    /** Two-tailed significance threshold. */
    public const ALPHA = 0.05;

    private const LAGS = [0, 1];

    /**
     * Significant correlations across many factors at both lags, strongest first.
     *
     * @param array<string, int|float>                                  $symptomByDate level keyed by 'Y-m-d'
     * @param array<string, array{label: string, data: array<string, int|float>}> $factors keyed by factor key
     * @return list<CorrelationResult>
     */
    public function significant(array $symptomByDate, array $factors): array
    {
        $results = [];
        foreach ($factors as $key => $factor) {
            foreach (self::LAGS as $lag) {
                $result = $this->analyze($symptomByDate, $factor['data'], $key, $factor['label'], $lag);
                if ($result !== null && $result->significant) {
                    $results[] = $result;
                }
            }
        }

        usort($results, static fn (CorrelationResult $a, CorrelationResult $b): int => abs($b->r) <=> abs($a->r));

        return $results;
    }

    /**
     * Correlate one factor against the symptom at a given lag. Pairs the factor on
     * day D with the symptom on day D+lag. Returns null when fewer than MIN_N pairs
     * or the correlation is undefined (no variance).
     *
     * @param array<string, int|float> $symptomByDate
     * @param array<string, int|float> $factorByDate
     */
    public function analyze(
        array $symptomByDate,
        array $factorByDate,
        string $factor,
        string $label,
        int $lagDays = 0,
    ): ?CorrelationResult {
        $xs = [];
        $ys = [];
        foreach ($factorByDate as $date => $value) {
            $symptomDate = $lagDays === 0
                ? (string) $date
                : (new \DateTimeImmutable((string) $date))->modify("+{$lagDays} day")->format('Y-m-d');

            if (!array_key_exists($symptomDate, $symptomByDate)) {
                continue;
            }

            $xs[] = (float) $value;
            $ys[] = (float) $symptomByDate[$symptomDate];
        }

        $n = count($xs);
        if ($n < self::MIN_N) {
            return null;
        }

        $r = $this->pearson($xs, $ys, $n);
        if ($r === null) {
            return null;
        }

        // Significance is computed from the full-precision coefficient; r is rounded
        // only for display, so a borderline case can't flip on a rounding artifact.
        $p = $this->twoTailedPValue($r, $n);

        return new CorrelationResult(
            factor: $factor,
            label: $label,
            r: round($r, 2),
            n: $n,
            pValue: $p,
            lagDays: $lagDays,
            significant: $p < self::ALPHA,
        );
    }

    /**
     * @param list<float> $x
     * @param list<float> $y
     */
    private function pearson(array $x, array $y, int $n): ?float
    {
        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $cov = $varX = $varY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $cov  += $dx * $dy;
            $varX += $dx * $dx;
            $varY += $dy * $dy;
        }

        if ($varX <= 0.0 || $varY <= 0.0) {
            return null;
        }

        // Full precision — the caller rounds for display after the significance test.
        return $cov / sqrt($varX * $varY);
    }

    /** Two-tailed p-value for a Pearson r over n samples (Student-t, df = n−2). */
    private function twoTailedPValue(float $r, int $n): float
    {
        $df = $n - 2;
        if ($df <= 0) {
            return 1.0;
        }
        if ($r * $r >= 1.0) {
            return 0.0;
        }

        $t = $r * sqrt($df / (1.0 - $r * $r));

        // p = I_x(df/2, 1/2) with x = df / (df + t²).
        return $this->incompleteBeta($df / 2.0, 0.5, $df / ($df + $t * $t));
    }

    /** Regularized incomplete beta function I_x(a, b) (Numerical Recipes). */
    private function incompleteBeta(float $a, float $b, float $x): float
    {
        if ($x <= 0.0) {
            return 0.0;
        }
        if ($x >= 1.0) {
            return 1.0;
        }

        $bt = exp(
            $this->logGamma($a + $b) - $this->logGamma($a) - $this->logGamma($b)
            + $a * log($x) + $b * log(1.0 - $x)
        );

        if ($x < ($a + 1.0) / ($a + $b + 2.0)) {
            return $bt * $this->betaContinuedFraction($a, $b, $x) / $a;
        }

        return 1.0 - $bt * $this->betaContinuedFraction($b, $a, 1.0 - $x) / $b;
    }

    private function betaContinuedFraction(float $a, float $b, float $x): float
    {
        $maxIterations = 200;
        $epsilon = 3.0e-12;
        $tiny = 1.0e-30;

        $qab = $a + $b;
        $qap = $a + 1.0;
        $qam = $a - 1.0;
        $c = 1.0;
        $d = 1.0 - $qab * $x / $qap;
        if (abs($d) < $tiny) {
            $d = $tiny;
        }
        $d = 1.0 / $d;
        $h = $d;

        for ($m = 1; $m <= $maxIterations; $m++) {
            $m2 = 2 * $m;
            $aa = $m * ($b - $m) * $x / (($qam + $m2) * ($a + $m2));
            $d = 1.0 + $aa * $d;
            if (abs($d) < $tiny) {
                $d = $tiny;
            }
            $c = 1.0 + $aa / $c;
            if (abs($c) < $tiny) {
                $c = $tiny;
            }
            $d = 1.0 / $d;
            $h *= $d * $c;

            $aa = -($a + $m) * ($qab + $m) * $x / (($a + $m2) * ($qap + $m2));
            $d = 1.0 + $aa * $d;
            if (abs($d) < $tiny) {
                $d = $tiny;
            }
            $c = 1.0 + $aa / $c;
            if (abs($c) < $tiny) {
                $c = $tiny;
            }
            $d = 1.0 / $d;
            $del = $d * $c;
            $h *= $del;

            if (abs($del - 1.0) < $epsilon) {
                break;
            }
        }

        return $h;
    }

    /** Lanczos approximation of ln Γ(x). */
    private function logGamma(float $x): float
    {
        $coefficients = [
            76.18009172947146,
            -86.50532032941677,
            24.01409824083091,
            -1.231739572450155,
            0.1208650973866179e-2,
            -0.5395239384953e-5,
        ];

        $y = $x;
        $tmp = $x + 5.5;
        $tmp -= ($x + 0.5) * log($tmp);
        $ser = 1.000000000190015;
        foreach ($coefficients as $coefficient) {
            $ser += $coefficient / ++$y;
        }

        return -$tmp + log(2.5066282746310005 * $ser / $x);
    }
}

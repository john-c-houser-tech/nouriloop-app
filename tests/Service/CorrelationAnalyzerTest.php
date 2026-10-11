<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CorrelationAnalyzer;
use PHPUnit\Framework\TestCase;

final class CorrelationAnalyzerTest extends TestCase
{
    private CorrelationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new CorrelationAnalyzer();
    }

    /** @return array<string, float> 'Y-m-d' => value, starting 2026-06-01 */
    private function series(array $values): array
    {
        $out = [];
        foreach ($values as $i => $v) {
            $out[(new \DateTimeImmutable('2026-06-01'))->modify("+{$i} day")->format('Y-m-d')] = (float) $v;
        }

        return $out;
    }

    public function testPerfectPositiveCorrelationIsSignificant(): void
    {
        $symptom = $this->series([1, 2, 3, 4, 5, 6, 7, 8]);
        $factor  = $this->series([2, 4, 6, 8, 10, 12, 14, 16]);

        $result = $this->analyzer->analyze($symptom, $factor, 'protein', 'Protein');

        self::assertNotNull($result);
        self::assertSame(1.0, $result->r);
        self::assertSame(8, $result->n);
        self::assertTrue($result->significant);
        self::assertLessThan(0.05, $result->pValue);
    }

    public function testPerfectNegativeCorrelationIsSignificant(): void
    {
        $symptom = $this->series([8, 7, 6, 5, 4, 3, 2, 1]);
        $factor  = $this->series([1, 2, 3, 4, 5, 6, 7, 8]);

        $result = $this->analyzer->analyze($symptom, $factor, 'fiber', 'Fiber');

        self::assertNotNull($result);
        self::assertSame(-1.0, $result->r);
        self::assertTrue($result->significant);
        self::assertFalse($result->isPositive());
    }

    public function testWeakCorrelationIsNotSignificant(): void
    {
        // Near-zero relationship over a modest sample → not significant.
        $symptom = $this->series([5, 4, 5, 6, 5, 4, 5, 6, 5, 4]);
        $factor  = $this->series([1, 9, 2, 8, 3, 7, 4, 6, 5, 5]);

        $result = $this->analyzer->analyze($symptom, $factor, 'steps', 'Steps');

        self::assertNotNull($result);
        self::assertFalse($result->significant);
    }

    public function testKnownPValueMatchesWithinTolerance(): void
    {
        // These two near-monotonic series give r ≈ 0.96 over n = 10 (df = 8), a large
        // t, and a two-tailed p well under 0.001 — comfortably significant.
        $symptom = $this->series([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        $factor  = $this->series([1, 3, 2, 5, 4, 6, 8, 7, 9, 11]);

        $result = $this->analyzer->analyze($symptom, $factor, 'carbs', 'Carbs');

        self::assertNotNull($result);
        self::assertEqualsWithDelta(0.96, $result->r, 0.02);
        self::assertLessThan(0.001, $result->pValue);
        self::assertTrue($result->significant);
    }

    public function testFewerThanMinPairsReturnsNull(): void
    {
        $symptom = $this->series([1, 2, 3, 4, 5, 6]); // 6 < MIN_N (7)
        $factor  = $this->series([2, 4, 6, 8, 10, 12]);

        self::assertNull($this->analyzer->analyze($symptom, $factor, 'fat', 'Fat'));
    }

    public function testNoVarianceReturnsNull(): void
    {
        $symptom = $this->series([5, 5, 5, 5, 5, 5, 5, 5]);
        $factor  = $this->series([1, 2, 3, 4, 5, 6, 7, 8]);

        self::assertNull($this->analyzer->analyze($symptom, $factor, 'weight', 'Weight'));
    }

    public function testNextDayLagAlignsFactorToFollowingDay(): void
    {
        // Factor on day D perfectly predicts the symptom on day D+1.
        $factor  = $this->series([1, 2, 3, 4, 5, 6, 7, 8]);
        // Symptom shifted one day later (so factor[D] == symptom[D+1]).
        $symptom = [];
        foreach ($factor as $date => $v) {
            $symptom[(new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')] = $v;
        }

        $sameDay = $this->analyzer->analyze($symptom, $factor, 'sleep', 'Sleep', 0);
        $nextDay = $this->analyzer->analyze($symptom, $factor, 'sleep', 'Sleep', 1);

        self::assertNotNull($nextDay);
        self::assertSame(1.0, $nextDay->r);
        self::assertSame(1, $nextDay->lagDays);
        // The next-day lag aligns all 8 days; same-day catches one fewer pair.
        self::assertNotNull($sameDay);
        self::assertSame(8, $nextDay->n);
        self::assertSame(7, $sameDay->n);
    }

    public function testSignificantReturnsOnlySignificantSortedByStrength(): void
    {
        $symptom = $this->series([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        $factors = [
            'protein' => ['label' => 'Protein', 'data' => $this->series([2, 4, 6, 8, 10, 12, 14, 16, 18, 20])], // r=1
            'noise'   => ['label' => 'Noise',   'data' => $this->series([5, 1, 6, 2, 7, 3, 8, 4, 9, 1])],       // weak
        ];

        $results = $this->analyzer->significant($symptom, $factors);

        self::assertNotEmpty($results);
        self::assertSame('protein', $results[0]->factor); // strongest first
        foreach ($results as $r) {
            self::assertTrue($r->significant);
            self::assertNotSame('noise', $r->factor);
        }
    }
}

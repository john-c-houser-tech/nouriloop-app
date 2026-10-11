<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DietSymptomCorrelationService;
use PHPUnit\Framework\TestCase;

final class DietSymptomCorrelationServiceTest extends TestCase
{
    private DietSymptomCorrelationService $svc;

    protected function setUp(): void
    {
        $this->svc = new DietSymptomCorrelationService();
    }

    public function testSameDayAveragesAndCorrelation(): void
    {
        $scores = ['2026-06-01' => 5.0, '2026-06-02' => 4.0, '2026-06-03' => -2.0, '2026-06-04' => -3.0];
        $pain   = ['2026-06-01' => 8,   '2026-06-02' => 7,   '2026-06-03' => 3,    '2026-06-04' => 2];

        $sd = $this->svc->summarize($scores, $pain)['sameDay'];

        self::assertSame(4, $sd['n']);
        self::assertSame(7.5, $sd['proAvgPain']);   // pro days (5, 4) → pain 8, 7
        self::assertSame(2.5, $sd['otherAvgPain']); // other days (-2, -3) → pain 3, 2
        self::assertNotNull($sd['r']);
        self::assertGreaterThan(0.9, $sd['r']);     // higher inflammation ↔ higher pain
    }

    public function testNextDayShiftsPainByOneDay(): void
    {
        $scores = ['2026-06-01' => 5.0, '2026-06-02' => 4.0, '2026-06-03' => -2.0, '2026-06-04' => -3.0];
        $pain   = ['2026-06-02' => 7,   '2026-06-03' => 3,   '2026-06-04' => 2]; // no 06-05

        $nd = $this->svc->summarize($scores, $pain)['nextDay'];

        // score 06-01→pain 06-02, 06-02→06-03, 06-03→06-04; 06-04→06-05 missing.
        self::assertSame(3, $nd['n']);
        self::assertSame(5.0, $nd['proAvgPain']);   // pro: 06-01(7), 06-02(3) → 5.0
        self::assertSame(2.0, $nd['otherAvgPain']); // other: 06-03(2)
    }

    public function testNoOverlapYieldsEmptySummary(): void
    {
        $sd = $this->svc->summarize(['2026-06-01' => 5.0], ['2026-06-10' => 8])['sameDay'];

        self::assertSame(0, $sd['n']);
        self::assertNull($sd['r']);
        self::assertNull($sd['proAvgPain']);
        self::assertNull($sd['otherAvgPain']);
    }

    public function testCorrelationNullWithoutVariance(): void
    {
        $sd = $this->svc->summarize(
            ['2026-06-01' => 5.0, '2026-06-02' => 5.0],
            ['2026-06-01' => 3,   '2026-06-02' => 7],
        )['sameDay'];

        self::assertSame(2, $sd['n']);
        self::assertNull($sd['r']); // no variance in scores → undefined
    }
}

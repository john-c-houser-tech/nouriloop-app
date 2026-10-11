<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\Reading;
use App\Enum\HealthMetricType;
use App\Enum\UnitSystem;
use PHPUnit\Framework\TestCase;

final class HealthMetricIngestionTest extends TestCase
{
    use CapturesHealthRows;

    public function testAReadingIsStoredInTheCanonicalUnitAtItsWallClockTime(): void
    {
        $result = $this->ingestion()->ingest($this->user(7), [
            $this->reading(HealthMetricType::Weight, 160, 'lb', '2026-06-10 08:32:00 -0700'),
        ]);

        self::assertSame(['imported' => 1, 'skipped' => 0, 'errors' => []], $result);
        self::assertSame([[
            'user_id' => 7,
            'metric_type' => 'weight',
            'value' => 72.57,
            'recorded_at' => '2026-06-10 08:32:00',
            'source' => 'Scale',
        ]], $this->rows);
    }

    public function testAnUnlabelledWeightFollowsTheAssumedUnitSystemOnlyWhenOneIsGiven(): void
    {
        $ingestion = $this->ingestion();
        $ingestion->ingest($this->user(), [$this->reading(HealthMetricType::Weight, 180, '')], UnitSystem::Imperial);
        $ingestion->ingest($this->user(), [$this->reading(HealthMetricType::Weight, 80, '')]);

        self::assertSame([81.65, 80.0], array_column($this->rows, 'value'));
    }

    public function testValuesAreRoundedToTheStoredPrecision(): void
    {
        $this->ingestion()->ingest($this->user(), [
            $this->reading(HealthMetricType::HeartRate, 72.33333, ''),
            $this->reading(HealthMetricType::Steps, 8123.6, ''),
            $this->reading(HealthMetricType::SleepCore, 95.4567, ''),
        ]);

        self::assertSame([72.33, 8124.0, 95.46], array_column($this->rows, 'value'));
    }

    public function testTheSourceNameIsCappedToItsColumn(): void
    {
        $this->ingestion()->ingest($this->user(), [
            new Reading(
                HealthMetricType::HeartRate,
                60,
                '',
                new \DateTimeImmutable('2026-06-10 08:00'),
                str_repeat('x', 200),
            ),
        ]);

        self::assertSame(100, strlen($this->rows[0]['source']));
    }

    public function testTheSourceCapCountsCharactersAndNeverSplitsOne(): void
    {
        $this->ingestion()->ingest($this->user(), [
            new Reading(
                HealthMetricType::HeartRate,
                60,
                '',
                new \DateTimeImmutable('2026-06-10 08:00'),
                'x' . str_repeat('é', 150),
            ),
        ]);

        self::assertSame(100, mb_strlen($this->rows[0]['source']));
        self::assertTrue(mb_check_encoding($this->rows[0]['source'], 'UTF-8'));
    }

    public function testImplausibleReadingsAreSkippedAndSaySo(): void
    {
        $result = $this->ingestion()->ingest($this->user(), [
            $this->reading(HealthMetricType::Weight, -70, 'kg', '2026-06-10 07:00:00 -0700'),
            $this->reading(HealthMetricType::HeartRate, 0, '', '2026-06-10 07:01:00 -0700'),
            $this->reading(HealthMetricType::SleepInBed, 1000, '', '2026-06-10 23:00:00 -0700'),
            $this->reading(HealthMetricType::HeartRate, 61, '', $this->from('+3 days')),
        ]);

        self::assertSame([], $this->rows);
        self::assertSame(0, $result['imported']);
        self::assertSame(4, $result['skipped']);
        self::assertSame([
            "Skipped weight at 2026-06-10 07:00: a negative value can't be measured.",
            "Skipped heart_rate at 2026-06-10 07:01: a zero value isn't a reading.",
            'Skipped sleep_in_bed at 2026-06-10 23:00: 1000 minutes of sleep is longer than any night.',
        ], array_slice($result['errors'], 0, 3));
        self::assertStringEndsWith(': it is in the future.', $result['errors'][3]);
    }

    public function testAZeroStepDayALongNightAndTomorrowMorningAreFine(): void
    {
        $result = $this->ingestion()->ingest($this->user(), [
            $this->reading(HealthMetricType::Steps, 0, '', '2026-06-10 12:00:00 -0700'),
            $this->reading(HealthMetricType::SleepInBed, 900, '', '2026-06-10 21:00:00 -0700'),
            $this->reading(HealthMetricType::HeartRate, 60, '', $this->from('+12 hours')),
        ]);

        self::assertSame(3, $result['imported']);
        self::assertSame(0, $result['skipped']);
    }

    public function testReadingsAreConsumedLazily(): void
    {
        $seen = 0;
        $readings = (function () use (&$seen): \Generator {
            for ($i = 0; $i < 3; $i++) {
                $seen++;
                yield $this->reading(HealthMetricType::HeartRate, 60 + $i, '', '2026-06-10 08:0' . $i . ':00 -0700');
            }
        })();

        $result = $this->ingestion()->ingest($this->user(), $readings);

        self::assertSame(3, $seen);
        self::assertSame(3, $result['imported']);
    }

    private function reading(
        HealthMetricType $type,
        float $value,
        string $unit,
        string $at = '2026-06-10 08:00:00 -0700',
    ): Reading {
        return new Reading($type, $value, $unit, new \DateTimeImmutable($at), 'Scale');
    }

    private function from(string $modifier): string
    {
        return (new \DateTimeImmutable($modifier))->format('Y-m-d H:i:s O');
    }
}

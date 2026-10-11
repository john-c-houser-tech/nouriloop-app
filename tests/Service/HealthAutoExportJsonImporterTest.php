<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\HealthAutoExportJsonImporter;
use App\Service\HealthMetricIngestion;
use App\Service\HealthMetricWriter;
use App\Service\UserTime;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class HealthAutoExportJsonImporterTest extends TestCase
{
    /**
     * @param  array<mixed> $data
     * @return array{result: array{imported:int,skipped:int,errors:string[]}, rows: list<array<string,mixed>>}
     */
    private function ingest(array $data): array
    {
        $rows = [];

        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params) use (&$rows): int {
                foreach (array_chunk($params, 5) as $chunk) {
                    $rows[] = [
                        'user_id'     => $chunk[0],
                        'metric_type' => $chunk[1],
                        'value'       => $chunk[2],
                        'recorded_at' => $chunk[3],
                        'source'      => $chunk[4],
                    ];
                }

                return intdiv(count($params), 5);
            }
        );

        $user = $this->createStub(User::class);
        $user->method('getTimezone')->willReturn('America/Los_Angeles');
        $user->method('getId')->willReturn(7);

        $importer = new HealthAutoExportJsonImporter($this->ingestion($connection));

        return ['result' => $importer->import($data, $user), 'rows' => $rows];
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function ofType(array $rows, string $type): array
    {
        return array_values(array_filter($rows, fn (array $r): bool => $r['metric_type'] === $type));
    }

    /**
     * @return array{result: array{imported:int,skipped:int,errors:string[]}, rows: list<array<string,mixed>>}
     */
    private function ingestSample(): array
    {
        $json = (string) file_get_contents(__DIR__ . '/fixtures/health-auto-export-sample.json');
        $data = json_decode($json, true);
        self::assertIsArray($data);

        return $this->ingest($data);
    }

    public function testBloodGlucoseMappedAsIsInMgDl(): void
    {
        $out = $this->ingestSample();
        $row = $this->ofType($out['rows'], 'blood_glucose')[0];

        self::assertSame(100.47, $row['value']);
        self::assertSame('2026-05-31 00:00:00', $row['recorded_at']);
        self::assertSame('Health Auto Export', $row['source']);
    }

    public function testConvertsMmolPerLitreGlucoseToMgDl(): void
    {
        $out = $this->ingest([
            'data' => ['metrics' => [[
                'name'  => 'blood_glucose',
                'units' => 'mmol/L',
                'data'  => [['date' => '2026-05-31 00:00:00 -0700', 'qty' => 5.5]],
            ]]],
        ]);

        // 5.5 mmol/L × 18.0182 ≈ 99.10 mg/dL
        self::assertSame(99.1, $this->ofType($out['rows'], 'blood_glucose')[0]['value']);
    }

    public function testWeightPoundsConvertedToKilograms(): void
    {
        $out = $this->ingestSample();
        self::assertSame(96.84, $this->ofType($out['rows'], 'weight')[0]['value']);
    }

    public function testHeartRateUsesAverageAndRestingIsIgnored(): void
    {
        $out = $this->ingestSample();
        $hr  = $this->ofType($out['rows'], 'heart_rate');

        // resting_heart_rate must NOT add a second heart_rate row (avoids collision).
        self::assertCount(1, $hr);
        self::assertSame(72.28, $hr[0]['value']);
    }

    public function testHeartRateFallsBackToQtyWhenDisaggregated(): void
    {
        $out = $this->ingest([
            'data' => ['metrics' => [[
                'name'  => 'heart_rate',
                'units' => 'count/min',
                'data'  => [['date' => '2026-05-12 08:30:00 -0700', 'qty' => 70]],
            ]]],
        ]);

        $hr = $this->ofType($out['rows'], 'heart_rate');
        self::assertCount(1, $hr);
        self::assertSame(70.0, $hr[0]['value']);
        self::assertSame('2026-05-12 08:30:00', $hr[0]['recorded_at']);
    }

    public function testStepCountRoundedToSteps(): void
    {
        $out = $this->ingestSample();
        self::assertSame(1559.0, $this->ofType($out['rows'], 'steps')[0]['value']);
    }

    public function testSleepHoursExpandedToStageMinutes(): void
    {
        $out = $this->ingestSample();
        $byType = fn (string $t): float => $this->ofType($out['rows'], $t)[0]['value'];

        self::assertSame(540.0, $byType('sleep_in_bed')); // 9.0 hr
        self::assertSame(240.0, $byType('sleep_core'));   // 4.0 hr
        self::assertSame(60.0, $byType('sleep_deep'));    // 1.0 hr
        self::assertSame(72.0, $byType('sleep_rem'));     // 1.2 hr
        self::assertSame([], $this->ofType($out['rows'], 'sleep_asleep'));
        // recorded_at = sleepStart wall-clock (offset dropped)
        self::assertSame('2026-05-11 22:11:00', $this->ofType($out['rows'], 'sleep_core')[0]['recorded_at']);
    }

    public function testUnmappedMetricsAndWorkoutsIgnored(): void
    {
        $out = $this->ingestSample();

        self::assertSame([], $this->ofType($out['rows'], 'blood_oxygen_saturation'));
        // blood_glucose + weight + heart_rate + steps + 4 sleep stages
        self::assertSame(8, $out['result']['imported']);
    }

    public function testSupportsOnlyItsOwnEnvelope(): void
    {
        $importer = new HealthAutoExportJsonImporter(
            $this->ingestion($this->createStub(Connection::class)),
        );

        self::assertTrue($importer->supports(['data' => ['metrics' => []]]));
        self::assertFalse($importer->supports(['exportInfo' => [], 'stepCount' => []]));
        self::assertSame('health-auto-export', $importer->key());
    }

    private function ingestion(Connection $connection): HealthMetricIngestion
    {
        return new HealthMetricIngestion(new HealthMetricWriter($connection), new UserTime());
    }
}

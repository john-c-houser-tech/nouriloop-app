<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\HealthExportJsonImporter;
use App\Service\HealthMetricIngestion;
use App\Service\HealthMetricWriter;
use App\Service\UserTime;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class HealthExportJsonImporterTest extends TestCase
{
    /**
     * Runs the importer against a payload, capturing the rows the writer would
     * insert so the mapping can be asserted directly.
     *
     * @param  array<mixed> $data
     * @return array{result: array{imported:int,skipped:int,errors:string[]}, rows: list<array<string,mixed>>}
     */
    private function import(array $data): array
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

                return intdiv(count($params), 5); // pretend every row inserted
            }
        );

        $user = $this->createStub(User::class);
        $user->method('getTimezone')->willReturn('America/Los_Angeles');
        $user->method('getId')->willReturn(7);

        $importer = new HealthExportJsonImporter($this->ingestion($connection));

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

    public function testConvertsWeightPoundsToKilograms(): void
    {
        $out = $this->import([
            'weight' => [['date' => '2026-06-10', 'unit' => 'lb', 'value' => 213.5]],
        ]);

        $row = $this->ofType($out['rows'], 'weight')[0];
        self::assertSame(7, $row['user_id']);
        self::assertSame(96.84, $row['value']);
        self::assertSame('2026-06-10 12:00:00', $row['recorded_at']);
        self::assertSame('Health Export', $row['source']);
    }

    public function testKilogramWeightPassesThrough(): void
    {
        $out = $this->import([
            'weight' => [['date' => '2026-06-10', 'unit' => 'kg', 'value' => 80]],
        ]);

        self::assertSame(80.0, $this->ofType($out['rows'], 'weight')[0]['value']);
    }

    public function testBodyFatFractionScaledToPercent(): void
    {
        $out = $this->import([
            'bodyFat' => [['date' => '2026-06-10', 'unit' => '%', 'value' => 0.183]],
        ]);

        self::assertSame(18.3, $this->ofType($out['rows'], 'body_fat_percentage')[0]['value']);
    }

    public function testBodyFatPlainPercentLeftAsIs(): void
    {
        $out = $this->import([
            'bodyFat' => [['date' => '2026-06-10', 'unit' => '%', 'value' => 18]],
        ]);

        self::assertSame(18.0, $this->ofType($out['rows'], 'body_fat_percentage')[0]['value']);
    }

    public function testRestingHeartRateMapsToHeartRate(): void
    {
        $out = $this->import([
            'restingHeartRate' => [['date' => '2026-06-10', 'unit' => 'bpm', 'value' => 63]],
        ]);

        self::assertSame(63.0, $this->ofType($out['rows'], 'heart_rate')[0]['value']);
    }

    public function testStepCountRoundedAndMappedToSteps(): void
    {
        $out = $this->import([
            'stepCount' => [['date' => '2026-06-10', 'unit' => 'count', 'value' => 1686.6160267111854]],
        ]);

        self::assertSame(1687.0, $this->ofType($out['rows'], 'steps')[0]['value']);
    }

    public function testSleepExpandsIntoStageRows(): void
    {
        $out = $this->import([
            'sleepTime' => [[
                'date'   => '2026-06-10',
                'unit'   => 'min',
                'value'  => 174.72,
                'stages' => [
                    'awake'       => 124.9,
                    'core'        => 90.8,
                    'deep'        => 38.96666666666667,
                    'rem'         => 44.95,
                    'unspecified' => 0,
                ],
            ]],
        ]);

        $byType = fn (string $t): float => $this->ofType($out['rows'], $t)[0]['value'];

        // In-bed = every stage including awake; Core/Deep/REM stored granularly;
        // no Awake row, no synthesized Asleep.
        self::assertSame(299.62, $byType('sleep_in_bed'));
        self::assertSame(90.8, $byType('sleep_core'));
        self::assertSame(38.97, $byType('sleep_deep'));
        self::assertSame(44.95, $byType('sleep_rem'));
        self::assertSame([], $this->ofType($out['rows'], 'sleep_asleep'));
        self::assertSame('2026-06-10 12:00:00', $this->ofType($out['rows'], 'sleep_core')[0]['recorded_at']);
    }

    public function testUnmappedTypesAndEmptyArraysProduceNoRows(): void
    {
        $out = $this->import([
            'exportInfo'        => ['appVersion' => '1.4.0'],
            'oxygenSaturation'  => [['date' => '2026-06-10', 'unit' => '%', 'value' => 0.965]],
            'vo2Max'            => [['date' => '2026-06-10', 'unit' => 'ml/kg/min', 'value' => 42]],
            'weight'            => [],
            'bodyFat'           => [],
        ]);

        self::assertSame([], $out['rows']);
        self::assertSame(0, $out['result']['imported']);
    }

    public function testSkipsRecordsWithMissingValueOrUnparseableDate(): void
    {
        $out = $this->import([
            'weight' => [
                ['date' => '2026-06-10', 'unit' => 'kg'],                  // no value
                ['date' => 'not-a-date', 'unit' => 'kg', 'value' => 80],  // bad date
                ['date' => '2026-06-11', 'unit' => 'kg', 'value' => 81],  // good
            ],
        ]);

        self::assertCount(1, $this->ofType($out['rows'], 'weight'));
        self::assertSame(81.0, $this->ofType($out['rows'], 'weight')[0]['value']);
        self::assertSame(2, $out['result']['skipped']);
    }

    public function testSupportsRecognisesOwnFormatOnly(): void
    {
        $importer = new HealthExportJsonImporter(
            $this->ingestion($this->createStub(Connection::class)),
        );

        self::assertTrue($importer->supports(['exportInfo' => [], 'weight' => []]));
        self::assertTrue($importer->supports(['stepCount' => []]));
        self::assertFalse($importer->supports(['glucoseReadings' => []]));
        self::assertSame('health-export', $importer->key());
    }

    public function testImportsTheRealSampleEnvelope(): void
    {
        $json = (string) file_get_contents(__DIR__ . '/fixtures/health-export-sample.json');
        $data = json_decode($json, true);
        self::assertIsArray($data);

        $out = $this->import($data);

        // weight(1) + restingHeartRate(1) + stepCount(2) + sleepTime(2 nights × 4 rows)
        self::assertSame(12, $out['result']['imported']);
        self::assertCount(1, $this->ofType($out['rows'], 'weight'));
        self::assertCount(2, $this->ofType($out['rows'], 'steps'));
        self::assertCount(2, $this->ofType($out['rows'], 'sleep_core'));
    }

    private function ingestion(Connection $connection): HealthMetricIngestion
    {
        return new HealthMetricIngestion(new HealthMetricWriter($connection), new UserTime());
    }
}

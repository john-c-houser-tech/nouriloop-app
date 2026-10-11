<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\HealthMetricApiParser;
use App\Service\HealthMetricIngestion;
use App\Service\HealthMetricWriter;
use App\Service\UserTime;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The ingestion API's records end to end: parsed into readings, then stored
 * through HealthMetricIngestion (units, rounding, source cap) against a stubbed
 * connection that captures the rows it would insert.
 */
final class HealthMetricApiParserTest extends TestCase
{
    public function testAValidRecordIsStoredAsSent(): void
    {
        $out = $this->ingest([
            [
                'type' => 'weight', 'value' => 72.4,
                'recordedAt' => '2026-06-10T08:32:00-07:00', 'source' => 'Apple Watch',
            ],
        ], 7);

        self::assertSame(0, $out['skipped']);
        self::assertSame([], $out['errors']);
        self::assertCount(1, $out['rows']);

        $row = $out['rows'][0];
        self::assertSame(7, $row['user_id']);
        self::assertSame('weight', $row['metric_type']);
        self::assertSame(72.4, $row['value']);
        // Wall-clock as sent (offset dropped), matching the XML importer.
        self::assertSame('2026-06-10 08:32:00', $row['recorded_at']);
        self::assertSame('Apple Watch', $row['source']);
    }

    public function testConvertsPoundsToKilograms(): void
    {
        $out = $this->ingest([
            ['type' => 'weight', 'value' => 160, 'unit' => 'lb', 'recordedAt' => '2026-06-10T08:00:00Z'],
        ], 1);

        self::assertSame(72.57, $out['rows'][0]['value']);
    }

    public function testConvertsMmolGlucoseToMgDl(): void
    {
        // Including the Apple export.xml spelling the sync CLI passes through verbatim.
        $out = $this->ingest([
            ['type' => 'blood_glucose', 'value' => 5.4, 'unit' => 'mmol/L', 'recordedAt' => '2026-06-10T08:00:00Z'],
            [
                'type' => 'blood_glucose',
                'value' => 6.1,
                'unit' => 'mmol<180.1558800000541>/L',
                'recordedAt' => '2026-06-10T09:00:00Z',
            ],
            ['type' => 'blood_glucose', 'value' => 104, 'unit' => 'mg/dL', 'recordedAt' => '2026-06-10T10:00:00Z'],
        ], 1);

        self::assertSame([97.3, 109.91, 104.0], array_column($out['rows'], 'value'));
    }

    public function testDefaultsAndTruncatesSource(): void
    {
        $out = $this->ingest([
            ['type' => 'heart_rate', 'value' => 60, 'recordedAt' => '2026-06-10T08:00:00Z'],
            [
                'type' => 'heart_rate', 'value' => 61, 'recordedAt' => '2026-06-10T08:01:00Z',
                'source' => str_repeat('x', 200),
            ],
        ], 1);

        self::assertSame('API', $out['rows'][0]['source']);
        self::assertSame(100, strlen($out['rows'][1]['source']));
    }

    public function testSkipsUnknownTypeWithReason(): void
    {
        $out = $this->ingest([
            ['type' => 'oxygen_saturation', 'value' => 98, 'recordedAt' => '2026-06-10T08:00:00Z'],
        ], 1);

        self::assertSame([], $out['rows']);
        self::assertSame(1, $out['skipped']);
        self::assertStringContainsString("unknown type 'oxygen_saturation'", $out['errors'][0]);
    }

    public function testSkipsNonNumericValueAndBadDate(): void
    {
        $out = $this->ingest([
            ['type' => 'weight', 'value' => 'heavy', 'recordedAt' => '2026-06-10T08:00:00Z'],
            ['type' => 'weight', 'value' => 70, 'recordedAt' => 'not-a-date'],
            ['type' => 'weight', 'value' => 70, 'recordedAt' => ''],
            'not-an-object',
        ], 1);

        self::assertSame([], $out['rows']);
        self::assertSame(4, $out['skipped']);
        self::assertCount(4, $out['errors']);
    }

    public function testMixedBatchKeepsGoodRowsAndReportsBad(): void
    {
        $out = $this->ingest([
            ['type' => 'weight', 'value' => 70, 'recordedAt' => '2026-06-10T08:00:00Z'],
            ['type' => 'bogus', 'value' => 1, 'recordedAt' => '2026-06-10T08:00:00Z'],
            ['type' => 'blood_glucose', 'value' => 98, 'recordedAt' => '2026-06-10T09:00:00Z'],
        ], 3);

        self::assertCount(2, $out['rows']);
        self::assertSame(1, $out['skipped']);
        self::assertSame('weight', $out['rows'][0]['metric_type']);
        self::assertSame('blood_glucose', $out['rows'][1]['metric_type']);
    }

    public function testValuesAreRoundedToTheStoredPrecision(): void
    {
        $out = $this->ingest([
            ['type' => 'heart_rate', 'value' => 72.33333, 'recordedAt' => '2026-06-01T08:00:00-07:00'],
            ['type' => 'steps', 'value' => 8123.6, 'recordedAt' => '2026-06-01T08:00:00-07:00'],
        ], 1);

        self::assertSame(72.33, $out['rows'][0]['value']);
        self::assertSame(8124.0, $out['rows'][1]['value']);
    }

    /**
     * @param  array<mixed> $metrics
     * @return array{rows: list<array<string, mixed>>, skipped: int, errors: string[]}
     */
    private function ingest(array $metrics, int $userId): array
    {
        $rows = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params) use (&$rows): int {
                foreach (array_chunk($params, 5) as [$user, $type, $value, $at, $source]) {
                    $rows[] = [
                        'user_id' => $user, 'metric_type' => $type, 'value' => $value,
                        'recorded_at' => $at, 'source' => $source,
                    ];
                }

                return count($params) / 5;
            },
        );
        $user = $this->createStub(User::class);
        $user->method('getTimezone')->willReturn('America/Los_Angeles');
        $user->method('getId')->willReturn($userId);

        $parsed = (new HealthMetricApiParser())->parse($metrics);
        ($this->ingestion($connection))->ingest($user, $parsed['readings']);

        return ['rows' => $rows, 'skipped' => $parsed['skipped'], 'errors' => $parsed['errors']];
    }

    private function ingestion(Connection $connection): HealthMetricIngestion
    {
        return new HealthMetricIngestion(new HealthMetricWriter($connection), new UserTime());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\UnitSystem;
use App\Service\HealthMetricCsvImporter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class HealthMetricCsvImporterTest extends TestCase
{
    use CapturesHealthRows;

    private string $file;

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testRowFormatHonoursAUnitColumnAndAPerRowSource(): void
    {
        $this->import(<<<'CSV'
            date,type,value,unit,source
            2026-06-10 08:00,weight,160,lb,Withings
            2026-06-10 09:00,blood glucose,5.4,mmol/L,
            CSV);

        self::assertSame([
            ['user_id' => 7, 'metric_type' => 'weight', 'value' => 72.57,
                'recorded_at' => '2026-06-10 08:00:00', 'source' => 'Withings'],
            ['user_id' => 7, 'metric_type' => 'blood_glucose', 'value' => 97.3,
                'recorded_at' => '2026-06-10 09:00:00', 'source' => ''],
        ], $this->rows);
    }

    public function testColumnarHeadersCarryUnitsAndAnUnlabelledWeightFollowsTheUser(): void
    {
        $this->import(<<<'CSV'
            date,weight,glucose (mmol/L),steps
            2026-06-10,180,5.4,8123.6
            CSV, UnitSystem::Imperial);

        self::assertSame(
            [['weight', 81.65], ['blood_glucose', 97.3], ['steps', 8124.0]],
            array_map(static fn(array $r): array => [$r['metric_type'], $r['value']], $this->rows),
        );
        self::assertSame('CSV Import', $this->rows[0]['source']);
    }

    public function testBadRowsAreSkippedWithTheirRowNumber(): void
    {
        $result = $this->import(<<<'CSV'
            date,type,value
            not a date,weight,70
            2026-06-10 08:00,weight
            2026-06-10 09:00,weight,71
            CSV);

        self::assertSame(1, $result['imported']);
        self::assertSame(2, $result['skipped']);
        self::assertSame(
            ['Row 2: could not parse date.', 'Row 3: column count does not match header.'],
            $result['errors'],
        );
    }

    public function testRowFormatIgnoresUntrackedTypesAndReportsValuesThatArentNumbers(): void
    {
        $result = $this->import(<<<'CSV'
            date,type,value
            2026-06-10 08:00,blood oxygen,98
            2026-06-10 08:05,weight,heavy
            2026-06-10 08:10,weight,70
            CSV);

        self::assertSame(1, $result['imported']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(["Row 3: 'heavy' in value isn't a number."], $result['errors']);
    }

    public function testColumnarIgnoresBlankCellsAndReportsCellsThatArentNumbers(): void
    {
        $result = $this->import(<<<'CSV'
            date,weight,heart rate,mood
            2026-06-10,70,,great
            2026-06-11,n/a,61,fine
            CSV);

        self::assertSame(
            [['weight', 70.0], ['heart_rate', 61.0]],
            array_map(static fn(array $r): array => [$r['metric_type'], $r['value']], $this->rows),
        );
        self::assertSame(1, $result['skipped']);
        self::assertSame(["Row 3: 'n/a' in weight isn't a number."], $result['errors']);
    }

    public function testADuplicateIsCountedAsSkippedNotImported(): void
    {
        // The unique key drops the second reading for the same type and time.
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $this->file = $this->write(<<<'CSV'
            date,type,value
            2026-06-10 08:00,weight,70
            2026-06-10 08:00,weight,70
            CSV);

        $result = (new HealthMetricCsvImporter($this->ingestionOver($connection)))->import($this->file, $this->user());

        self::assertSame(['imported' => 1, 'skipped' => 1, 'errors' => []], $result);
    }

    /** @return array{imported: int, skipped: int, errors: string[]} */
    private function import(string $csv, UnitSystem $units = UnitSystem::Metric): array
    {
        $this->file = $this->write($csv);

        return (new HealthMetricCsvImporter($this->ingestion()))->import($this->file, $this->user(7, $units));
    }

    private function write(string $csv): string
    {
        $path = tempnam(sys_get_temp_dir(), 'metrics') . '.csv';
        file_put_contents($path, $csv . "\n");

        return $path;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AppleHealthXmlImporter;
use PHPUnit\Framework\TestCase;

final class AppleHealthXmlImporterTest extends TestCase
{
    use CapturesHealthRows;

    private string $file;

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testScalarRecordsBecomeCanonicalReadings(): void
    {
        $this->import(<<<'XML'
            <Record type="HKQuantityTypeIdentifierBloodGlucose" sourceName="Libre"
                    unit="mmol&lt;180.1558800000541&gt;/L" startDate="2026-06-15 08:00:00 -0700" value="5.4"/>
            <Record type="HKQuantityTypeIdentifierHeartRate" sourceName="Watch"
                    unit="count/min" startDate="2026-06-15 07:05:00 -0700" value="72.4567"/>
            XML);

        self::assertSame([
            ['user_id' => 7, 'metric_type' => 'blood_glucose', 'value' => 97.3,
                'recorded_at' => '2026-06-15 08:00:00', 'source' => 'Libre'],
            ['user_id' => 7, 'metric_type' => 'heart_rate', 'value' => 72.46,
                'recorded_at' => '2026-06-15 07:05:00', 'source' => 'Watch'],
        ], $this->rows);
    }

    public function testASleepSegmentIsStoredAsMinutesAtItsStart(): void
    {
        $this->import(<<<'XML'
            <Record type="HKCategoryTypeIdentifierSleepAnalysis" sourceName="Watch"
                    value="HKCategoryValueSleepAnalysisAsleepCore"
                    startDate="2026-06-15 23:10:00 -0700" endDate="2026-06-16 00:40:30 -0700"/>
            XML);

        self::assertSame(
            [['user_id' => 7, 'metric_type' => 'sleep_core', 'value' => 90.5,
                'recorded_at' => '2026-06-15 23:10:00', 'source' => 'Watch']],
            $this->rows,
        );
    }

    public function testABackwardsSleepSegmentIsSkippedWithAReason(): void
    {
        $result = $this->import(<<<'XML'
            <Record type="HKCategoryTypeIdentifierSleepAnalysis" sourceName="Watch"
                    value="HKCategoryValueSleepAnalysisAsleepDeep"
                    startDate="2026-06-16 01:00:00 -0700" endDate="2026-06-16 00:30:00 -0700"/>
            XML);

        self::assertSame([], $this->rows);
        self::assertSame(1, $result['skipped']);
        self::assertSame(
            ["Skipped sleep_deep at 2026-06-16 01:00: a negative value can't be measured."],
            $result['errors'],
        );
    }

    public function testStepsAreNotImportedFromAppleHealth(): void
    {
        // Per-device step samples would double-count every Watch wearer's day.
        $this->import(<<<'XML'
            <Record type="HKQuantityTypeIdentifierStepCount" sourceName="iPhone"
                    unit="count" startDate="2026-06-15 08:00:00 -0700" value="1200"/>
            XML);

        self::assertSame([], $this->rows);
    }

    public function testRecordsIgnoredOnPurposeAreNotCountedAsSkipped(): void
    {
        $result = $this->import(<<<'XML'
            <Record type="HKQuantityTypeIdentifierStepCount" sourceName="iPhone"
                    unit="count" startDate="2026-06-15 08:00:00 -0700" value="1200"/>
            <Record type="HKQuantityTypeIdentifierActiveEnergyBurned" sourceName="Watch"
                    unit="kcal" startDate="2026-06-15 08:00:00 -0700" value="12"/>
            <Record type="HKCategoryTypeIdentifierSleepAnalysis" sourceName="Watch"
                    value="HKCategoryValueSleepAnalysisAwake"
                    startDate="2026-06-16 02:00:00 -0700" endDate="2026-06-16 02:10:00 -0700"/>
            <Record type="HKQuantityTypeIdentifierHeartRate" sourceName="Watch"
                    unit="count/min" startDate="2026-06-15 07:05:00 -0700" value="62"/>
            XML);

        self::assertSame(['imported' => 1, 'skipped' => 0, 'errors' => []], $result);
    }

    public function testAnUnparseableDateIsReported(): void
    {
        $result = $this->import(<<<'XML'
            <Record type="HKQuantityTypeIdentifierHeartRate" sourceName="Watch"
                    unit="count/min" startDate="yesterday-ish" value="60"/>
            XML);

        self::assertSame([], $this->rows);
        self::assertSame(1, $result['skipped']);
        self::assertSame(['Unparseable date: yesterday-ish'], $result['errors']);
    }

    /** @return array{imported: int, skipped: int, errors: string[]} */
    private function import(string $records): array
    {
        $this->file = tempnam(sys_get_temp_dir(), 'export') . '.xml';
        file_put_contents(
            $this->file,
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<HealthData>\n" . $records . "\n</HealthData>\n",
        );

        return (new AppleHealthXmlImporter($this->ingestion()))->import($this->file, $this->user());
    }
}

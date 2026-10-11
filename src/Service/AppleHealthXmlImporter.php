<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Reading;
use App\Entity\User;
use App\Enum\HealthMetricType;

class AppleHealthXmlImporter
{
    private const SLEEP_HK_TYPE = 'HKCategoryTypeIdentifierSleepAnalysis';

    // Maps every importable sleep stage to its dedicated metric_type.
    // HKCategoryValueSleepAnalysisAwake is intentionally excluded.
    // Storing each stage separately lets the nightly aggregation choose the
    // most granular data available without double-counting.
    private const SLEEP_STAGE_MAP = [
        'HKCategoryValueSleepAnalysisInBed'     => HealthMetricType::SleepInBed,
        'HKCategoryValueSleepAnalysisAsleep'     => HealthMetricType::SleepAsleep,
        'HKCategoryValueSleepAnalysisAsleepCore' => HealthMetricType::SleepCore,
        'HKCategoryValueSleepAnalysisAsleepDeep' => HealthMetricType::SleepDeep,
        'HKCategoryValueSleepAnalysisAsleepREM'  => HealthMetricType::SleepREM,
    ];

    private const TYPE_MAP = [
        'HKQuantityTypeIdentifierBodyMass'               => HealthMetricType::Weight,
        'HKQuantityTypeIdentifierBloodGlucose'           => HealthMetricType::BloodGlucose,
        'HKQuantityTypeIdentifierBloodPressureSystolic'  => HealthMetricType::BloodPressureSystolic,
        'HKQuantityTypeIdentifierBloodPressureDiastolic' => HealthMetricType::BloodPressureDiastolic,
        'HKQuantityTypeIdentifierHeartRate'              => HealthMetricType::HeartRate,
        'HKQuantityTypeIdentifierBodyFatPercentage'      => HealthMetricType::BodyFatPercentage,
    ];

    public function __construct(
        private readonly HealthMetricIngestion $ingestion,
    ) {
    }

    /**
     * Streams an Apple Health export.xml and inserts records in batches.
     * Duplicate records (same user + metric type + timestamp) are silently skipped.
     *
     * @return array{imported: int, skipped: int, errors: string[]}
     */
    public function import(string $filePath, User $user): array
    {
        if (!is_readable($filePath)) {
            throw new \RuntimeException("File not readable: {$filePath}");
        }

        $reader = new \XMLReader();
        if (!$reader->open($filePath)) {
            throw new \RuntimeException("Cannot open file: {$filePath}");
        }

        // Records that should have become readings but couldn't (bad values, bad dates).
        $skipped = 0;
        $errors  = [];

        // Ingestion drives iteration, so readings stream straight from the
        // reader without ever materialising the whole file in memory.
        $result = $this->ingestion->ingest($user, $this->readings($reader, $skipped, $errors));

        $reader->close();

        return [
            'imported' => $result['imported'],
            'skipped'  => $skipped + $result['skipped'],
            'errors'   => array_merge($errors, $result['errors']),
        ];
    }

    /**
     * Yields readings from the reader; rejects (unrecognized types,
     * unparseable values/dates) bump $skipped/$errors.
     *
     * @param  string[] $errors
     * @return \Generator<Reading>
     */
    private function readings(\XMLReader $reader, int &$skipped, array &$errors): \Generator
    {
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'Record') {
                continue;
            }

            $hkType = $reader->getAttribute('type') ?? '';

            // Sleep is a category type: value = stage string, duration = endDate - startDate.
            if ($hkType === self::SLEEP_HK_TYPE) {
                $stage      = $reader->getAttribute('value') ?? '';
                $metricEnum = self::SLEEP_STAGE_MAP[$stage] ?? null;
                if ($metricEnum === null) {
                    // Awake or an unrecognized stage: ignored on purpose, not a skip.
                    continue;
                }

                $startDate = $this->parseDate($reader->getAttribute('startDate'));
                $endDate   = $this->parseDate($reader->getAttribute('endDate'));
                $source    = $reader->getAttribute('sourceName') ?? 'Apple Health';

                if ($startDate === null || $endDate === null) {
                    $skipped++;
                    continue;
                }

                // Ingestion refuses a segment that is empty, backwards or longer than any night.
                $minutes = ($endDate->getTimestamp() - $startDate->getTimestamp()) / 60;

                yield new Reading($metricEnum, $minutes, '', $startDate, $source);
                continue;
            }

            $metricType = self::TYPE_MAP[$hkType] ?? null;

            if ($metricType === null) {
                // A type NouriLoop doesn't track (or, for steps, deliberately
                // doesn't take from Apple Health): ignored, not a skip. An
                // export holds thousands of these, which drowned out the
                // count of records that really couldn't be used.
                continue;
            }

            $rawValue = $reader->getAttribute('value');
            $rawDate  = $reader->getAttribute('startDate');
            $rawUnit  = $reader->getAttribute('unit') ?? '';
            $source   = $reader->getAttribute('sourceName') ?? 'Apple Health';

            $value = filter_var($rawValue, FILTER_VALIDATE_FLOAT);
            if ($value === false) {
                $skipped++;
                continue;
            }

            $date = $this->parseDate($rawDate);
            if ($date === null) {
                $errors[] = "Unparseable date: {$rawDate}";
                $skipped++;
                continue;
            }

            yield new Reading($metricType, (float) $value, $rawUnit, $date, $source);
        }
    }

    private function parseDate(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        // Apple format: "2024-01-15 08:32:00 -0800"
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s O', $raw);

        return $dt !== false ? $dt : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Reading;
use App\Entity\User;
use App\Enum\HealthMetricType;

/**
 * Imports the JSON produced by the "Health Auto Export – JSON+CSV" iPhone app:
 * a `data.metrics[]` list where each entry has a `name`, a metric-level `units`,
 * and a `data[]` array of daily records. Most metrics are `{date, qty}`;
 * heart_rate carries `{Min, Max, Avg}` (we use Avg) and sleep_analysis carries
 * per-stage hours plus real timestamps.
 *
 * One of several {@see HealthJsonImporter} strategies; yields readings to the
 * shared HealthMetricIngestion (units, rounding, dedup and batching for free).
 */
final class HealthAutoExportJsonImporter implements HealthJsonImporter
{
    private const SOURCE = 'Health Auto Export';

    /** Simple `{date, qty}` metrics → metric type. */
    private const SCALAR_MAP = [
        'blood_glucose'            => HealthMetricType::BloodGlucose,
        'weight_body_mass'         => HealthMetricType::Weight,
        'step_count'               => HealthMetricType::Steps,
        'blood_pressure_systolic'  => HealthMetricType::BloodPressureSystolic,
        'blood_pressure_diastolic' => HealthMetricType::BloodPressureDiastolic,
    ];

    public function __construct(
        private readonly HealthMetricIngestion $ingestion,
    ) {
    }

    public function key(): string
    {
        return 'health-auto-export';
    }

    public function label(): string
    {
        return 'Health Auto Export';
    }

    /**
     * Recognised by its `data.metrics` envelope.
     *
     * @param array<mixed> $data
     */
    public function supports(array $data): bool
    {
        return isset($data['data']['metrics']) && is_array($data['data']['metrics']);
    }

    /**
     * @param  array<mixed> $data
     * @return array{imported: int, skipped: int, errors: string[]}
     */
    public function import(array $data, User $user): array
    {
        $skipped = 0;
        $errors  = [];

        $result = $this->ingestion->ingest($user, $this->readings($data, $skipped, $errors));

        return [
            'imported' => $result['imported'],
            'skipped'  => $skipped + $result['skipped'],
            'errors'   => array_merge($errors, $result['errors']),
        ];
    }

    /**
     * @param  array<mixed> $data
     * @param  string[]     $errors
     * @return \Generator<Reading>
     */
    private function readings(array $data, int &$skipped, array &$errors): \Generator
    {
        $metrics = $data['data']['metrics'] ?? [];
        if (!is_array($metrics)) {
            return;
        }

        foreach ($metrics as $metric) {
            if (!is_array($metric)) {
                continue;
            }

            $name    = (string) ($metric['name'] ?? '');
            $units   = (string) ($metric['units'] ?? '');
            $records = $metric['data'] ?? null;
            if (!is_array($records)) {
                continue;
            }

            // resting_heart_rate is intentionally not mapped: heart_rate (Avg) is the
            // daily heart-rate value, and mapping both would collide on (type, time).
            if ($name === 'heart_rate') {
                foreach ($records as $rec) {
                    $reading = $this->heartRateReading($rec);
                    if ($reading === null) {
                        $skipped++;
                    } else {
                        yield $reading;
                    }
                }
                continue;
            }

            if ($name === 'sleep_analysis') {
                foreach ($records as $rec) {
                    yield from $this->sleepReadings($rec, $skipped);
                }
                continue;
            }

            $type = self::SCALAR_MAP[$name] ?? null;
            if ($type === null) {
                continue; // unmapped metric — ignored, not counted
            }

            foreach ($records as $rec) {
                $reading = $this->scalarReading($rec, $type, $units);
                if ($reading === null) {
                    $skipped++;
                } else {
                    yield $reading;
                }
            }
        }
    }

    /**
     * @param  mixed $rec
     */
    private function scalarReading(mixed $rec, HealthMetricType $type, string $units): ?Reading
    {
        if (!is_array($rec)) {
            return null;
        }

        $value = filter_var($rec['qty'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($value === false) {
            return null;
        }

        $recordedAt = $this->parseDate($rec['date'] ?? null);
        if ($recordedAt === null) {
            return null;
        }

        return new Reading($type, (float) $value, $units, $recordedAt, self::SOURCE);
    }

    /**
     * @param  mixed $rec
     */
    private function heartRateReading(mixed $rec): ?Reading
    {
        if (!is_array($rec)) {
            return null;
        }

        // Summarised exports carry Min/Max/Avg; disaggregated ones a plain qty.
        $value = filter_var($rec['Avg'] ?? $rec['qty'] ?? null, FILTER_VALIDATE_FLOAT);
        $recordedAt = $this->parseDate($rec['date'] ?? null);
        if ($value === false || $recordedAt === null) {
            return null;
        }

        return new Reading(HealthMetricType::HeartRate, (float) $value, '', $recordedAt, self::SOURCE);
    }

    /**
     * Expands one nightly sleep record into per-stage readings. Health Auto
     * Export reports stages in hours → stored as minutes. In-bed (the app's own
     * figure) + Core/Deep/REM are kept; awake is excluded, and the `asleep` /
     * `totalSleep` total is not stored: Core/Deep/REM already cover it, and the
     * nightly total prefers them over an asleep figure anyway. (The Apple Health
     * XML import does store an Asleep segment when Apple reports one.)
     *
     * @param  mixed $rec
     * @return \Generator<Reading>
     */
    private function sleepReadings(mixed $rec, int &$skipped): \Generator
    {
        $recordedAt = is_array($rec)
            ? $this->parseDate($rec['sleepStart'] ?? $rec['inBedStart'] ?? $rec['date'] ?? null)
            : null;
        if ($recordedAt === null) {
            $skipped++;

            return;
        }

        $emit = [
            [HealthMetricType::SleepInBed, $this->hoursToMinutes($rec['inBed'] ?? null)],
            [HealthMetricType::SleepCore,  $this->hoursToMinutes($rec['core'] ?? null)],
            [HealthMetricType::SleepDeep,  $this->hoursToMinutes($rec['deep'] ?? null)],
            [HealthMetricType::SleepREM,   $this->hoursToMinutes($rec['rem'] ?? null)],
        ];

        $yielded = false;
        foreach ($emit as [$type, $minutes]) {
            if ($minutes <= 0) {
                continue;
            }
            $yielded = true;
            yield new Reading($type, $minutes, '', $recordedAt, self::SOURCE);
        }

        if (!$yielded) {
            $skipped++;
        }
    }

    private function hoursToMinutes(mixed $hours): float
    {
        $float = filter_var($hours, FILTER_VALIDATE_FLOAT);

        return $float === false ? 0.0 : (float) $float * 60;
    }

    /**
     * Parses "2026-05-31 00:00:00 -0700"; the local wall-clock time is what
     * gets stored (the offset is dropped), as with the Apple Health XML import.
     */
    private function parseDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s O', $raw);

        return $dt !== false ? $dt : null;
    }
}

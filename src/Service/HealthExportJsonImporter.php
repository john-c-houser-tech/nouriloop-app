<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Reading;
use App\Entity\User;
use App\Enum\HealthMetricType;

/**
 * Imports the JSON produced by the iPhone "Health Export" app (the format with a
 * root-level `exportInfo` envelope and camelCase metric keys, each a list of
 * `{date, unit, value}` daily records). Maps the subset NouriLoop tracks, leaning
 * on each record's explicit `unit` so no measurement-system guessing is needed.
 *
 * Like AppleHealthXmlImporter, it yields readings to the shared
 * HealthMetricIngestion, so units, rounding, dedup (INSERT IGNORE on
 * uq_health_metric) and batching come for free and re-running an overlapping
 * export is safe.
 */
final class HealthExportJsonImporter implements HealthJsonImporter
{
    private const SOURCE = 'Health Export';

    /** Scalar daily metrics: one `{date, unit, value}` record → one row. */
    private const SCALAR_MAP = [
        'weight'           => HealthMetricType::Weight,
        'bodyFat'          => HealthMetricType::BodyFatPercentage,
        'restingHeartRate' => HealthMetricType::HeartRate,
        'stepCount'        => HealthMetricType::Steps,
    ];

    public function __construct(
        private readonly HealthMetricIngestion $ingestion,
    ) {
    }

    public function key(): string
    {
        return 'health-export';
    }

    public function label(): string
    {
        return 'Health Export';
    }

    /**
     * Recognises the format by its root `exportInfo` envelope, falling back to
     * its distinctive camelCase metric keys.
     *
     * @param array<mixed> $data
     */
    public function supports(array $data): bool
    {
        return isset($data['exportInfo'])
            || isset($data['stepCount'])
            || isset($data['sleepTime'])
            || isset($data['restingHeartRate']);
    }

    /**
     * Imports an already-decoded Health Export payload (the API path).
     *
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
        foreach (self::SCALAR_MAP as $key => $type) {
            $records = $data[$key] ?? null;
            if (!is_array($records)) {
                continue; // metric absent from this export — not an error
            }

            foreach ($records as $rec) {
                $reading = $this->scalarReading($rec, $type);
                if ($reading === null) {
                    $skipped++;
                    continue;
                }
                yield $reading;
            }
        }

        $sleep = $data['sleepTime'] ?? null;
        if (is_array($sleep)) {
            foreach ($sleep as $rec) {
                yield from $this->sleepReadings($rec, $skipped);
            }
        }
    }

    /**
     * @param  mixed $rec
     */
    private function scalarReading(mixed $rec, HealthMetricType $type): ?Reading
    {
        if (!is_array($rec)) {
            return null;
        }

        $value = filter_var($rec['value'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($value === false) {
            return null;
        }

        $recordedAt = $this->dailyTimestamp($rec['date'] ?? null);
        if ($recordedAt === null) {
            return null;
        }

        // Percentages arrive as a 0–1 fraction (cf. oxygen saturation 0.965);
        // ingestion scales body fat up.
        return new Reading($type, (float) $value, (string) ($rec['unit'] ?? ''), $recordedAt, self::SOURCE);
    }

    /**
     * Expands one nightly sleep record into per-stage readings (minutes).
     * Core/Deep/REM are stored granularly. This app has no in-bed figure of its
     * own, so in-bed is the sum of every stage, awake included (Health Auto
     * Export's is the app's own figure). No Awake row and no aggregate Asleep
     * row is stored, so the nightly total never double-counts.
     *
     * @param  mixed $rec
     * @return \Generator<Reading>
     */
    private function sleepReadings(mixed $rec, int &$skipped): \Generator
    {
        $stages     = is_array($rec) ? ($rec['stages'] ?? null) : null;
        $recordedAt = is_array($rec) ? $this->dailyTimestamp($rec['date'] ?? null) : null;
        if ($recordedAt === null || !is_array($stages)) {
            $skipped++;

            return;
        }

        $core        = $this->minutes($stages['core'] ?? null);
        $deep        = $this->minutes($stages['deep'] ?? null);
        $rem         = $this->minutes($stages['rem'] ?? null);
        $awake       = $this->minutes($stages['awake'] ?? null);
        $unspecified = $this->minutes($stages['unspecified'] ?? null);

        $emit = [
            [HealthMetricType::SleepInBed, $core + $deep + $rem + $awake + $unspecified],
            [HealthMetricType::SleepCore,  $core],
            [HealthMetricType::SleepDeep,  $deep],
            [HealthMetricType::SleepREM,   $rem],
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

    /**
     * Health Export dates are local, date-only (e.g. "2026-06-10"). Pin each to
     * noon so the dedup key is stable across re-exports and the reading lands
     * squarely on the right local day regardless of timezone.
     */
    private function dailyTimestamp(mixed $date): ?\DateTimeImmutable
    {
        if (!is_string($date) || $date === '') {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $dt !== false ? $dt->setTime(12, 0) : null;
    }

    private function minutes(mixed $value): float
    {
        $float = filter_var($value, FILTER_VALIDATE_FLOAT);

        return $float === false ? 0.0 : (float) $float;
    }
}

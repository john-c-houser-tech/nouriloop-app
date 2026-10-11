<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Reading;
use App\Entity\User;
use App\Enum\HealthMetricType;

class HealthMetricCsvImporter
{
    // Maps common CSV column names to HealthMetricType enum cases
    private const COLUMN_MAP = [
        'weight'                   => HealthMetricType::Weight,
        'body weight'              => HealthMetricType::Weight,
        'blood glucose'            => HealthMetricType::BloodGlucose,
        'glucose'                  => HealthMetricType::BloodGlucose,
        'blood sugar'              => HealthMetricType::BloodGlucose,
        'systolic'                 => HealthMetricType::BloodPressureSystolic,
        'blood pressure systolic'  => HealthMetricType::BloodPressureSystolic,
        'diastolic'                => HealthMetricType::BloodPressureDiastolic,
        'blood pressure diastolic' => HealthMetricType::BloodPressureDiastolic,
        'heart rate'               => HealthMetricType::HeartRate,
        'pulse'                    => HealthMetricType::HeartRate,
        'body fat'                 => HealthMetricType::BodyFatPercentage,
        'body fat %'               => HealthMetricType::BodyFatPercentage,
        'body fat percentage'      => HealthMetricType::BodyFatPercentage,
        'steps'                    => HealthMetricType::Steps,
        'step count'               => HealthMetricType::Steps,
    ];

    /**
     * Unit suffixes a columnar header may carry — "weight (lb)", "weight_lbs",
     * "glucose mmol/l" — longest first so "lbs" wins over "lb".
     */
    private const HEADER_UNITS = ['mmol/l', 'mg/dl', 'mmol', 'mgdl', 'lbs', 'lb', 'kg'];

    public function __construct(
        private readonly HealthMetricIngestion $ingestion,
    ) {
    }

    /**
     * Imports health metrics from a CSV file.
     * Expected columns: date, type (or metric), value, [unit], [source]
     * OR a columnar format: date, weight, blood_glucose, heart_rate, ...
     *
     * A CSV is the one source with no units of its own: an unlabelled weight is
     * taken in the user's unit system.
     *
     * @return array{imported: int, skipped: int, errors: string[]}
     */
    public function import(string $filePath, User $user, string $source = 'CSV Import'): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open CSV file.');
        }

        $skipped = 0;
        $errors  = [];

        try {
            $result = $this->ingestion->ingest(
                $user,
                $this->readings($handle, $source, $skipped, $errors),
                $user->getUnitSystem(),
            );
        } finally {
            fclose($handle);
        }

        return [
            'imported' => $result['imported'],
            'skipped'  => $skipped + $result['skipped'],
            'errors'   => array_merge($errors, $result['errors']),
        ];
    }

    /**
     * Yields readings line by line; rows that can't be read bump
     * $skipped/$errors with their row number.
     *
     * @param  resource $handle
     * @param  string[] $errors
     * @return \Generator<Reading>
     */
    private function readings($handle, string $source, int &$skipped, array &$errors): \Generator
    {
        $headers = array_map(
            fn($h) => strtolower(trim((string) $h)),
            (array) fgetcsv($handle, escape: '\\')
        );

        // Detect format: row-per-metric vs columnar
        $isRowFormat = in_array('type', $headers, true) || in_array('metric', $headers, true);
        $row = 1;

        while (($line = fgetcsv($handle, escape: '\\')) !== false) {
            $row++;

            // array_combine() throws a ValueError on a length mismatch in PHP 8,
            // so guard explicitly rather than relying on a falsy return.
            if (count($headers) !== count($line)) {
                $errors[] = "Row {$row}: column count does not match header.";
                $skipped++;
                continue;
            }

            $data = array_combine($headers, array_map('trim', $line));

            $date = $this->parseDate($data['date'] ?? $data['datetime'] ?? null);
            if ($date === null) {
                $errors[] = "Row {$row}: could not parse date.";
                $skipped++;
                continue;
            }

            if ($isRowFormat) {
                yield from $this->rowFormatReadings($data, $row, $date, $source, $skipped, $errors);
            } else {
                yield from $this->columnarReadings($data, $headers, $row, $date, $source, $skipped, $errors);
            }
        }
    }

    /**
     * The row's one reading. A type NouriLoop doesn't track is ignored on
     * purpose (not a skip); a value that isn't a number is skipped and reported.
     *
     * @param array<string, string> $data
     * @param string[]              $errors
     *
     * @return \Generator<Reading>
     */
    private function rowFormatReadings(
        array $data,
        int $row,
        \DateTimeImmutable $date,
        string $source,
        int &$skipped,
        array &$errors,
    ): \Generator {
        $type = self::COLUMN_MAP[strtolower(trim($data['type'] ?? $data['metric'] ?? ''))] ?? null;
        if ($type === null) {
            return;
        }

        $value = $this->number($data['value'] ?? '', $row, 'value', $skipped, $errors);
        if ($value === null) {
            return;
        }

        // An optional `unit` column labels the row; without one, ingestion falls
        // back to the user's unit system (weight) and the mmol/L plausibility
        // ceiling (glucose).
        yield new Reading($type, $value, (string) ($data['unit'] ?? ''), $date, $data['source'] ?? $source);
    }

    /**
     * A reading per tracked column. Columns NouriLoop doesn't track and blank
     * cells (no reading that day) are ignored; a cell that isn't a number is
     * skipped and reported.
     *
     * @param array<string, string> $data
     * @param list<string>          $headers
     * @param string[]              $errors
     *
     * @return \Generator<Reading>
     */
    private function columnarReadings(
        array $data,
        array $headers,
        int $row,
        \DateTimeImmutable $date,
        string $source,
        int &$skipped,
        array &$errors,
    ): \Generator {
        foreach ($headers as $header) {
            if (in_array($header, ['date', 'datetime', 'time', 'source'], true)) {
                continue;
            }

            [$name, $unit] = $this->splitHeaderUnit($header);
            $type = self::COLUMN_MAP[$name] ?? null;
            if ($type === null || ($data[$header] ?? '') === '') {
                continue;
            }

            $value = $this->number($data[$header], $row, $header, $skipped, $errors);
            if ($value !== null) {
                yield new Reading($type, $value, $unit, $date, $source);
            }
        }
    }

    /** @param string[] $errors */
    private function number(string $raw, int $row, string $column, int &$skipped, array &$errors): ?float
    {
        $value = filter_var($raw, FILTER_VALIDATE_FLOAT);
        if ($value === false) {
            $skipped++;
            $errors[] = sprintf("Row %d: '%s' in %s isn't a number.", $row, mb_substr($raw, 0, 40), $column);

            return null;
        }

        return (float) $value;
    }

    /**
     * "weight (lb)" → ['weight', 'lb']; "glucose_mmol" → ['glucose', 'mmol'];
     * "heart rate" → ['heart rate', ''].
     *
     * @return array{0: string, 1: string}
     */
    private function splitHeaderUnit(string $header): array
    {
        $header = trim(str_replace('_', ' ', $header));
        foreach (self::HEADER_UNITS as $unit) {
            foreach (['(' . $unit . ')', '[' . $unit . ']', ' ' . $unit] as $suffix) {
                if (str_ends_with($header, $suffix)) {
                    return [rtrim(substr($header, 0, -strlen($suffix))), $unit === 'mgdl' ? 'mg/dl' : $unit];
                }
            }
        }

        return [$header, ''];
    }

    private function parseDate(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}

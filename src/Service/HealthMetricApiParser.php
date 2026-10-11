<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Reading;
use App\Enum\HealthMetricType;

/**
 * Turns the JSON `metrics` array from the ingestion API into readings,
 * validating each record. Invalid records are skipped (with a per-index reason)
 * rather than failing the whole batch, so one bad row from a device doesn't
 * reject an otherwise-good sync.
 */
final class HealthMetricApiParser
{
    /**
     * @param  array<mixed> $metrics decoded JSON "metrics" array
     * @return array{readings: list<Reading>, skipped: int, errors: string[]}
     */
    public function parse(array $metrics): array
    {
        $readings = [];
        $skipped  = 0;
        $errors   = [];

        foreach ($metrics as $i => $metric) {
            if (!is_array($metric)) {
                $skipped++;
                $errors[] = "metrics[{$i}]: expected an object.";
                continue;
            }

            $type = HealthMetricType::tryFrom((string) ($metric['type'] ?? ''));
            if ($type === null) {
                $skipped++;
                $errors[] = sprintf("metrics[%s]: unknown type '%s'.", $i, (string) ($metric['type'] ?? ''));
                continue;
            }

            $value = filter_var($metric['value'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($value === false) {
                $skipped++;
                $errors[] = "metrics[{$i}]: value is missing or not numeric.";
                continue;
            }

            $date = $this->parseDate((string) ($metric['recordedAt'] ?? ''));
            if ($date === null) {
                $skipped++;
                $errors[] = "metrics[{$i}]: recordedAt is missing or not a valid ISO-8601 date.";
                continue;
            }

            $readings[] = new Reading(
                $type,
                (float) $value,
                (string) ($metric['unit'] ?? ''),
                $date,
                trim((string) ($metric['source'] ?? '')) ?: 'API',
            );
        }

        return ['readings' => $readings, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        try {
            // The wall-clock time as sent is what gets stored (the offset is
            // dropped), matching how the Apple Health XML import stores it.
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}

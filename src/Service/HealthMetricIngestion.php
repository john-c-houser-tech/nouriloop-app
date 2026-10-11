<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Reading;
use App\Entity\User;
use App\Enum\HealthMetricType;
use App\Enum\UnitSystem;

/**
 * Readings in, canonical health metrics out: the one way a reading is stored,
 * whatever its source (Apple Health XML, the health-export JSON apps, the
 * ingestion API). It converts to the canonical unit, rounds to the stored
 * precision, refuses implausible readings, caps the source name to its column,
 * and keeps the wall-clock time; the writer batches the inserts and lets the
 * unique key drop duplicates.
 * Readings are consumed lazily, so a streamed export never sits in memory.
 */
final class HealthMetricIngestion
{
    private const SOURCE_MAX_LENGTH = 100;

    /** No night (or stage of one) runs longer than this; in minutes. */
    private const SLEEP_MAX_MINUTES = 960;

    /** Slack for "not in the future": a source's clock and zone may sit ahead of the user's. */
    private const FUTURE_SLACK = '+1 day';

    private const SLEEP_TYPES = [
        HealthMetricType::SleepInBed,
        HealthMetricType::SleepAsleep,
        HealthMetricType::SleepCore,
        HealthMetricType::SleepDeep,
        HealthMetricType::SleepREM,
    ];

    public function __construct(
        private readonly HealthMetricWriter $writer,
        private readonly UserTime $userTime,
    ) {
    }

    /**
     * @param iterable<Reading> $readings
     * @param UnitSystem|null   $assumeForUnlabelled the unit system an unlabelled weight
     *                                               is in; only for sources with no units
     *                                               of their own (CSV)
     *
     * @return array{imported: int, skipped: int, errors: string[]}
     */
    public function ingest(User $user, iterable $readings, ?UnitSystem $assumeForUnlabelled = null): array
    {
        $skipped = 0;
        $errors  = [];
        $latest  = $this->userTime->now($user)->modify(self::FUTURE_SLACK)->format('Y-m-d H:i:s');

        $result = $this->writer->write(
            $this->rows((int) $user->getId(), $readings, $assumeForUnlabelled, $latest, $skipped, $errors),
        );

        return [
            'imported' => $result['imported'],
            'skipped'  => $skipped + $result['skipped'],
            'errors'   => array_merge($errors, $result['errors']),
        ];
    }

    /**
     * @param iterable<Reading> $readings
     * @param string            $latest the latest plausible wall-clock time
     * @param string[]          $errors
     *
     * @return \Generator<array{user_id:int,metric_type:string,value:float,recorded_at:string,source:string}>
     */
    private function rows(
        int $userId,
        iterable $readings,
        ?UnitSystem $assumeForUnlabelled,
        string $latest,
        int &$skipped,
        array &$errors,
    ): \Generator {
        foreach ($readings as $reading) {
            $value = HealthUnitNormalizer::round($reading->type, HealthUnitNormalizer::toCanonical(
                $reading->type,
                $reading->value,
                $reading->unit,
                $assumeForUnlabelled,
            ));
            $recordedAt = $reading->recordedAt->format('Y-m-d H:i:s');

            $problem = $this->implausibility($reading->type, $value, $recordedAt, $latest);
            if ($problem !== null) {
                $skipped++;
                $errors[] = sprintf(
                    'Skipped %s at %s: %s.',
                    $reading->type->value,
                    $reading->recordedAt->format('Y-m-d H:i'),
                    $problem,
                );
                continue;
            }

            yield [
                'user_id'     => $userId,
                'metric_type' => $reading->type->value,
                'value'       => $value,
                'recorded_at' => $recordedAt,
                // Characters, not bytes: the column holds 100 characters, and a byte
                // cut can split a multi-byte one into invalid UTF-8.
                'source'      => mb_substr($reading->source, 0, self::SOURCE_MAX_LENGTH),
            ];
        }
    }

    /** Why a canonical value can't be a real reading, or null when it can. */
    private function implausibility(HealthMetricType $type, float $value, string $recordedAt, string $latest): ?string
    {
        if ($value < 0) {
            return 'a negative value can\'t be measured';
        }
        // A zero-step day is real; a zero weight, heart rate or night is a missing reading.
        if ($value == 0 && $type !== HealthMetricType::Steps) {
            return 'a zero value isn\'t a reading';
        }
        if (in_array($type, self::SLEEP_TYPES, true) && $value > self::SLEEP_MAX_MINUTES) {
            return sprintf('%s minutes of sleep is longer than any night', $value);
        }
        if ($recordedAt > $latest) {
            return 'it is in the future';
        }

        return null;
    }
}

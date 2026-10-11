<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HealthMetric;
use App\Entity\User;
use App\Enum\HealthMetricType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HealthMetric>
 */
class HealthMetricRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly Connection $connection,
    ) {
        parent::__construct($registry, HealthMetric::class);
    }

    /**
     * @return HealthMetric[]
     */
    public function findByUserAndType(
        User $user,
        HealthMetricType $type,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->createQueryBuilder('h')
            ->andWhere('h.user = :user')
            ->andWhere('h.metricType = :type')
            ->andWhere('h.recordedAt >= :from')
            ->andWhere('h.recordedAt < :to')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('h.recordedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the value of the user's most recent reading of one metric type
     * (by recordedAt), or null if they have none. Used for the GLP-1 protein
     * target, which scales to the latest body weight.
     */
    public function findLatestValue(User $user, HealthMetricType $type): ?float
    {
        $value = $this->createQueryBuilder('h')
            ->select('h.value')
            ->andWhere('h.user = :user')
            ->andWhere('h.metricType = :type')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->orderBy('h.recordedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $value !== null ? (float) $value['value'] : null;
    }

    /**
     * Returns the timestamp of the user's most recent health metric, or null if none.
     */
    public function findLatestRecordedAt(User $user): ?\DateTimeImmutable
    {
        $latest = $this->createQueryBuilder('h')
            ->select('MAX(h.recordedAt)')
            ->andWhere('h.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return $latest !== null ? new \DateTimeImmutable((string) $latest) : null;
    }

    /**
     * Whether the user has any blood-glucose reading at all — a cheap EXISTS used to
     * decide whether to surface glucose-response UI (dashboard card / insights link).
     */
    public function userHasGlucose(User $user): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT EXISTS(
                SELECT 1 FROM health_metric
                WHERE user_id = :user AND metric_type = 'blood_glucose'
            )",
            ['user' => $user->getId()],
        );
    }

    /**
     * Returns time-bucketed aggregates (avg/min/max/count) for one metric type.
     * Granularity: 'hour' → one row per hour (good for 7–14 day views)
     *              'day'  → one row per day  (good for 30–90 day views)
     *
     * @return list<array{bucket: string, avg: float, min: float, max: float, count: int}>
     */
    public function getAggregates(
        User $user,
        HealthMetricType $type,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $granularity = 'hour',
    ): array {
        $bucketExpr = $granularity === 'day'
            ? "DATE(recorded_at)"
            : "DATE_FORMAT(recorded_at, '%Y-%m-%d %H:00')";

        // Aggregate per the metric's summary type: SUM for cumulative totals (steps),
        // AVG otherwise. A total has no spread, so its min/max collapse to the total
        // (the chart then draws no range band). See MetricSummary.
        $summary   = $type->summary();
        $valueExpr = $summary->valueAggregate() . '(value)';
        $minExpr   = $summary->collapsesRange() ? $valueExpr : 'MIN(value)';
        $maxExpr   = $summary->collapsesRange() ? $valueExpr : 'MAX(value)';

        $rows = $this->connection->fetchAllAssociative(
            "SELECT {$bucketExpr} AS bucket,
                    {$valueExpr} AS avg,
                    {$minExpr} AS min,
                    {$maxExpr} AS max,
                    COUNT(*)   AS count
             FROM health_metric
             WHERE user_id = :user
               AND metric_type = :type
               AND recorded_at >= :from
               AND recorded_at < :to
             GROUP BY {$bucketExpr}
             ORDER BY bucket ASC",
            [
                'user' => $user->getId(),
                'type' => $type->value,
                'from' => $from->format('Y-m-d H:i:s'),
                'to'   => $to->format('Y-m-d H:i:s'),
            ]
        );

        return array_map(static fn(array $r) => [
            'bucket' => $r['bucket'],
            'avg'    => round((float) $r['avg'], 2),
            'min'    => round((float) $r['min'], 2),
            'max'    => round((float) $r['max'], 2),
            'count'  => (int) $r['count'],
        ], $rows);
    }

    /**
     * Raw blood-glucose readings within an arbitrary time window, oldest first —
     * the per-reading series a meal's postprandial response curve is assembled from.
     * Lightweight (DBAL, value + timestamp only); the uq_health_metric index
     * (user_id, metric_type, recorded_at) covers it.
     *
     * Both ends are INCLUSIVE, unlike this repository's day-range queries (which
     * take a half-open [from, to) with `to` = the next day's start): a meal's
     * window is two instants around the meal, not a run of days, and a reading
     * landing exactly on either edge belongs in it.
     *
     * @return list<array{recordedAt: string, value: string}>
     */
    public function findGlucoseInWindow(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->connection->fetchAllAssociative(
            "SELECT recorded_at AS recordedAt, value
             FROM health_metric
             WHERE user_id    = :user
               AND metric_type = 'blood_glucose'
               AND recorded_at >= :from
               AND recorded_at <= :to
             ORDER BY recorded_at ASC",
            [
                'user' => $user->getId(),
                'from' => $from->format('Y-m-d H:i:s'),
                'to'   => $to->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Timestamps of the user's most recent blood-glucose readings since $from,
     * newest first — the CGM setup wizard's density check reads these to tell a
     * per-reading feed (~5-min gaps) from an aggregated one (one point per day).
     * Same index coverage as findGlucoseInWindow (uq_health_metric).
     *
     * @return list<string> recorded_at values ('Y-m-d H:i:s'), newest first
     */
    public function findRecentGlucoseTimestamps(User $user, \DateTimeImmutable $from, int $limit = 300): array
    {
        return $this->connection->fetchFirstColumn(
            "SELECT recorded_at
             FROM health_metric
             WHERE user_id    = :user
               AND metric_type = 'blood_glucose'
               AND recorded_at >= :from
             ORDER BY recorded_at DESC
             LIMIT " . max(1, $limit),
            [
                'user' => $user->getId(),
                'from' => $from->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Max blood glucose in the 6 PM–10 PM window each day (post-dinner response proxy).
     *
     * @return list<array{date: string, peak: float, avg: float}>
     */
    public function getEveningGlucoseByDate(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->connection->fetchAllAssociative(
            "SELECT DATE(recorded_at)  AS date,
                    MAX(value)         AS peak,
                    ROUND(AVG(value),1) AS avg
             FROM health_metric
             WHERE user_id    = :user
               AND metric_type = 'blood_glucose'
               AND recorded_at >= :from
               AND recorded_at < :to
               AND HOUR(recorded_at) BETWEEN 18 AND 21
             GROUP BY DATE(recorded_at)
             ORDER BY date ASC",
            [
                'user' => $user->getId(),
                'from' => $from->format('Y-m-d H:i:s'),
                'to'   => $to->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Average blood glucose midnight–6 AM, attributed to the PREVIOUS calendar date
     * (i.e. the night that began on that date).
     *
     * @return list<array{night_date: string, avg: float, min: float, max: float}>
     */
    public function getOvernightGlucoseByDate(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->connection->fetchAllAssociative(
            "SELECT DATE(recorded_at - INTERVAL 6 HOUR) AS night_date,
                    ROUND(AVG(value),1)                  AS avg,
                    ROUND(MIN(value),1)                  AS min,
                    ROUND(MAX(value),1)                  AS max
             FROM health_metric
             WHERE user_id    = :user
               AND metric_type = 'blood_glucose'
               AND recorded_at >= :from
               AND recorded_at < :to
               AND HOUR(recorded_at) BETWEEN 0 AND 5
             GROUP BY DATE(recorded_at - INTERVAL 6 HOUR)
             ORDER BY night_date ASC",
            [
                'user' => $user->getId(),
                'from' => $from->format('Y-m-d H:i:s'),
                'to'   => $to->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Aggregates sleep segments into nightly totals.
     * Uses a noon-boundary: segments starting before noon belong to the previous night.
     * Returns one row per night with total sleep (hours) and the session onset time.
     *
     * @return list<array{sleep_date: string, total_hours: float, onset_time: string}>
     */
    public function getSleepNightlyData(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        // Priority: detailed stages (Core+Deep+REM) → legacy Asleep → InBed.
        // COALESCE drops to the next tier when a tier's SUM is NULL (no rows matched).
        // This avoids double-counting on new Apple Watch exports that contain both
        // an InBed record AND granular stage records for the same night.
        return $this->connection->fetchAllAssociative(
            "SELECT DATE(recorded_at - INTERVAL 12 HOUR) AS sleep_date,
                    MIN(recorded_at)                      AS onset_time,
                    ROUND(COALESCE(
                        NULLIF(SUM(CASE WHEN metric_type IN ('sleep_core','sleep_deep','sleep_rem')
                                        THEN value END), 0),
                        NULLIF(SUM(CASE WHEN metric_type = 'sleep_asleep'
                                        THEN value END), 0),
                        SUM(CASE WHEN metric_type = 'sleep_in_bed'
                                 THEN value END)
                    ) / 60, 2)                            AS total_hours
             FROM health_metric
             WHERE user_id    = :user
               AND metric_type IN ('sleep_in_bed','sleep_asleep','sleep_core','sleep_deep','sleep_rem')
               AND recorded_at >= :from
               AND recorded_at < :to
             GROUP BY DATE(recorded_at - INTERVAL 12 HOUR)
             HAVING total_hours >= 0.5
             ORDER BY sleep_date ASC",
            [
                'user' => $user->getId(),
                'from' => $from->format('Y-m-d H:i:s'),
                'to'   => $to->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Returns daily average values per metric type for the given date range.
     * Result: array<string, array{date: string, value: float}[]>
     * Keys are HealthMetricType enum values (e.g. 'blood_glucose').
     *
     * @return array<string, list<array{date: string, value: float}>>
     */
    public function getDailyAveragesPerType(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT metric_type,
                    DATE(recorded_at) AS date,
                    AVG(value)        AS value
             FROM health_metric
             WHERE user_id = :user
               AND recorded_at >= :from
               AND recorded_at < :to
             GROUP BY metric_type, DATE(recorded_at)
             ORDER BY metric_type, DATE(recorded_at) ASC',
            [
                'user' => $user->getId(),
                'from' => $from->format('Y-m-d H:i:s'),
                'to'   => $to->format('Y-m-d H:i:s'),
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['metric_type']][] = [
                'date'  => $row['date'],
                'value' => round((float) $row['value'], 2),
            ];
        }

        return $result;
    }
}

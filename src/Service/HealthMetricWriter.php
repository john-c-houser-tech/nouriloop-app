<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Batched, dedup-safe writer for health_metric rows, shared by every ingestion
 * source (Apple Health XML import, the JSON API). Dedup is delegated to the
 * `uq_health_metric` unique constraint via INSERT IGNORE, so re-sends and
 * overlapping syncs never create duplicates.
 */
final class HealthMetricWriter
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Inserts normalized rows in batches. Rows are consumed lazily, so a
     * generator streaming a huge export never materialises in memory.
     *
     * @param  iterable<array{user_id:int,metric_type:string,value:float,recorded_at:string,source:string}> $rows
     * @return array{imported: int, skipped: int, errors: string[]}
     */
    public function write(iterable $rows): array
    {
        $batch    = [];
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($rows as $row) {
            $batch[] = $row;

            if (count($batch) >= self::BATCH_SIZE) {
                [$ins, $skip] = $this->flush($batch, $errors);
                $imported += $ins;
                $skipped  += $skip;
                $batch = [];
            }
        }

        if (!empty($batch)) {
            [$ins, $skip] = $this->flush($batch, $errors);
            $imported += $ins;
            $skipped  += $skip;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param  array<array{user_id:int,metric_type:string,value:float,recorded_at:string,source:string}> $batch
     * @param  string[] $errors
     * @return array{int, int} [inserted, skipped]
     */
    private function flush(array $batch, array &$errors): array
    {
        if (empty($batch)) {
            return [0, 0];
        }

        $placeholders = implode(', ', array_fill(0, count($batch), '(?, ?, ?, ?, ?)'));
        $sql = "INSERT IGNORE INTO health_metric
                    (user_id, metric_type, value, recorded_at, source)
                VALUES {$placeholders}";

        $params = [];
        foreach ($batch as $row) {
            $params[] = $row['user_id'];
            $params[] = $row['metric_type'];
            $params[] = $row['value'];
            $params[] = $row['recorded_at'];
            $params[] = $row['source'];
        }

        try {
            $affected = $this->connection->executeStatement($sql, $params);
            $skipped  = count($batch) - $affected;

            return [(int) $affected, (int) $skipped];
        } catch (\Throwable $e) {
            $errors[] = 'Batch insert error: ' . $e->getMessage();

            return [0, count($batch)];
        }
    }
}

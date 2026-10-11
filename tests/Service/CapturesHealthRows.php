<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\UnitSystem;
use App\Service\HealthMetricIngestion;
use App\Service\HealthMetricWriter;
use App\Service\UserTime;
use Doctrine\DBAL\Connection;

/**
 * A real HealthMetricIngestion over a stubbed connection: the rows it would
 * insert land in $this->rows, and every row counts as inserted.
 */
trait CapturesHealthRows
{
    /** @var list<array{user_id:int,metric_type:string,value:float,recorded_at:string,source:string}> */
    private array $rows = [];

    private function ingestion(): HealthMetricIngestion
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params): int {
                foreach (array_chunk($params, 5) as [$user, $type, $value, $at, $source]) {
                    $this->rows[] = [
                        'user_id' => $user, 'metric_type' => $type, 'value' => $value,
                        'recorded_at' => $at, 'source' => $source,
                    ];
                }

                return intdiv(count($params), 5);
            },
        );

        return new HealthMetricIngestion(new HealthMetricWriter($connection), new UserTime());
    }

    /** Ingestion over a given connection, for tests that script the database's answers. */
    private function ingestionOver(Connection $connection): HealthMetricIngestion
    {
        return new HealthMetricIngestion(new HealthMetricWriter($connection), new UserTime());
    }

    private function user(int $id = 7, UnitSystem $units = UnitSystem::Metric): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getTimezone')->willReturn('America/Los_Angeles');
        $user->method('getUnitSystem')->willReturn($units);

        return $user;
    }
}

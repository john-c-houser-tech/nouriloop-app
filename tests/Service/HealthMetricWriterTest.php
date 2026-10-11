<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\HealthMetricWriter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class HealthMetricWriterTest extends TestCase
{
    /**
     * @return array{user_id:int,metric_type:string,value:float,recorded_at:string,source:string}
     */
    private function row(int $i): array
    {
        return [
            'user_id'     => 1,
            'metric_type' => 'weight',
            'value'       => 70.0 + $i,
            'recorded_at' => '2026-06-10 08:00:0' . ($i % 10),
            'source'      => 'Test',
        ];
    }

    public function testWritesInBatchesOfFiveHundred(): void
    {
        $rows = array_map(fn (int $i) => $this->row($i), range(1, 1200));

        $connection = $this->createMock(Connection::class);
        // 1200 rows → three flushes (500, 500, 200); each "inserts" all of its rows.
        $connection->expects(self::exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(fn (string $sql, array $params): int => intdiv(count($params), 5));

        $result = (new HealthMetricWriter($connection))->write($rows);

        self::assertSame(1200, $result['imported']);
        self::assertSame(0, $result['skipped']);
        self::assertSame([], $result['errors']);
    }

    public function testDedupCountsRowsTheDatabaseIgnored(): void
    {
        $connection = $this->createStub(Connection::class);
        // INSERT IGNORE reports only 1 of 3 rows as affected → 2 were duplicates.
        $connection->method('executeStatement')->willReturn(1);

        $result = (new HealthMetricWriter($connection))->write([
            $this->row(1), $this->row(2), $this->row(3),
        ]);

        self::assertSame(1, $result['imported']);
        self::assertSame(2, $result['skipped']);
    }

    public function testBatchErrorIsRecordedAndRowsCountedSkipped(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')
            ->willThrowException(new \RuntimeException('deadlock'));

        $result = (new HealthMetricWriter($connection))->write([$this->row(1), $this->row(2)]);

        self::assertSame(0, $result['imported']);
        self::assertSame(2, $result['skipped']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('deadlock', $result['errors'][0]);
    }

    public function testEmptyInputWritesNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        self::assertSame(
            ['imported' => 0, 'skipped' => 0, 'errors' => []],
            (new HealthMetricWriter($connection))->write([]),
        );
    }
}

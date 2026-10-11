<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\UserTime;
use PHPUnit\Framework\TestCase;

final class UserTimeTest extends TestCase
{
    private function userIn(string $tz): User
    {
        return (new User())->setTimezone($tz);
    }

    public function testNowIsExpressedInTheUserZone(): void
    {
        $clock = new \DateTimeImmutable('2026-06-12 03:30:00', new \DateTimeZone('UTC'));
        $ut = new UserTime();

        // 03:30 UTC is 23:30 the previous day in New York (EDT, -4).
        $ny = $ut->now($this->userIn('America/New_York'), $clock);
        self::assertSame('2026-06-11 23:30', $ny->format('Y-m-d H:i'));
        self::assertSame('America/New_York', $ny->getTimezone()->getName());
    }

    public function testStartOfTodayIsLocalMidnightAndDiffersAcrossZones(): void
    {
        // 03:30 UTC: still "yesterday" in New York, already "today" in London.
        $clock = new \DateTimeImmutable('2026-06-12 03:30:00', new \DateTimeZone('UTC'));
        $ut = new UserTime();

        $ny = $ut->startOfToday($this->userIn('America/New_York'), $clock);
        $london = $ut->startOfToday($this->userIn('Europe/London'), $clock);
        self::assertSame('2026-06-11 00:00:00', $ny->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-12 00:00:00', $london->format('Y-m-d H:i:s'));
    }

    public function testRangeHelpers(): void
    {
        $clock = new \DateTimeImmutable('2026-06-12 18:00:00', new \DateTimeZone('UTC'));
        $ut = new UserTime();
        $u = $this->userIn('America/Los_Angeles');

        self::assertSame('2026-06-05 00:00:00', $ut->startOfDaysAgo($u, 7, $clock)->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-13 00:00:00', $ut->startOfTomorrow($u, $clock)->format('Y-m-d H:i:s'));
    }

    public function testLocalHour(): void
    {
        $clock = new \DateTimeImmutable('2026-06-12 03:30:00', new \DateTimeZone('UTC'));
        $ut = new UserTime();

        self::assertSame(23, $ut->localHour($this->userIn('America/New_York'), $clock));
        self::assertSame(4, $ut->localHour($this->userIn('Europe/London'), $clock));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationCategory;
use App\Service\InAppNotifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class InAppNotifierTest extends TestCase
{
    public function testNotifyPersistsAndFlushesAShapedNotification(): void
    {
        $user = new User();

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (Notification $n) use (&$persisted): bool {
                $persisted = $n;

                return true;
            }));
        $em->expects(self::once())->method('flush');

        $notifier = new InAppNotifier($em);
        $when = new \DateTimeImmutable('2026-06-27 09:00:00');
        $result = $notifier->notify(
            $user,
            NotificationCategory::GoalCheckin,
            'Fiber goal — On track',
            'Nice work this week.',
            '/goals/7',
            $when,
        );

        self::assertSame($persisted, $result);
        self::assertSame($user, $result->getUser());
        self::assertSame(NotificationCategory::GoalCheckin, $result->getCategory());
        self::assertSame('Fiber goal — On track', $result->getTitle());
        self::assertSame('Nice work this week.', $result->getBody());
        self::assertSame('/goals/7', $result->getLinkPath());
        self::assertSame($when, $result->getCreatedAt());
        self::assertFalse($result->isRead());
    }
}

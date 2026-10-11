<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\GoalCheckin;
use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Service\Delivery\GoalDeliveryDispatcher;
use App\Service\Delivery\GoalDeliveryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\LocaleSwitcher;

final class GoalDeliveryDispatcherTest extends TestCase
{
    private function checkin(NotificationChannel $channel): GoalCheckin
    {
        // Delivery now follows the owner's notification channel setting.
        $user = (new User())->setNotificationChannel($channel);
        $goal = (new NutritionGoal())->setUser($user);

        return (new GoalCheckin())->setGoal($goal);
    }

    public function testDeliversOnlyOverSupportedTransports(): void
    {
        $supported = $this->createMock(GoalDeliveryInterface::class);
        $supported->method('supports')->willReturn(true);
        $supported->expects(self::once())->method('deliver');

        $unsupported = $this->createMock(GoalDeliveryInterface::class);
        $unsupported->method('supports')->willReturn(false);
        $unsupported->expects(self::never())->method('deliver');

        $dispatcher = new GoalDeliveryDispatcher(
            [$supported, $unsupported],
            new NullLogger(),
            new LocaleSwitcher('en', []),
        );
        $dispatcher->dispatch($this->checkin(NotificationChannel::Email));
    }

    public function testOneTransportFailingDoesNotBlockOthers(): void
    {
        $failing = $this->createStub(GoalDeliveryInterface::class);
        $failing->method('supports')->willReturn(true);
        $failing->method('deliver')->willThrowException(new \RuntimeException('boom'));

        $other = $this->createMock(GoalDeliveryInterface::class);
        $other->method('supports')->willReturn(true);
        $other->expects(self::once())->method('deliver');

        $dispatcher = new GoalDeliveryDispatcher(
            [$failing, $other],
            new NullLogger(),
            new LocaleSwitcher('en', []),
        );

        // Must not throw — failures are logged and swallowed.
        $dispatcher->dispatch($this->checkin(NotificationChannel::Both));
    }
}

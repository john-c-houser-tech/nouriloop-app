<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SendNotificationsCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Service\UserTime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Per-user failure isolation: one user's exception must not cost the users after
 * them their once-a-day local-8-PM window, and the run must still fail loudly.
 */
final class SendNotificationsCommandTest extends TestCase
{
    public function testOneFailingUserDoesNotStopTheRestAndFailsTheRun(): void
    {
        $broken = (new User())->setEmail('broken@test.local');
        $fine = (new User())->setEmail('fine@test.local');

        $users = $this->createStub(UserRepository::class);
        $users->method('findReceivingScheduledMessages')->willReturn([$broken, $fine]);

        $reached = [];
        $notifier = $this->createStub(NotificationService::class);
        $notifier->method('sendDueNotifications')->willReturnCallback(
            static function (User $user) use ($broken, &$reached): array {
                if ($user === $broken) {
                    throw new \RuntimeException('DB went away');
                }
                $reached[] = $user->getEmail();

                return ['No meals today'];
            },
        );

        $tester = new CommandTester(new SendNotificationsCommand($users, $notifier, new UserTime()));
        $tester->execute(['--force' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(['fine@test.local'], $reached);
        self::assertStringContainsString('Failed to notify broken@test.local', $tester->getDisplay());
        self::assertStringContainsString('1 failed', $tester->getDisplay());
    }
}

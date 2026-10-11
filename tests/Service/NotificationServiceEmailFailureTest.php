<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\NotificationLog;
use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Repository\HealthMetricRepository;
use App\Intake\DailyIntake;
use App\Repository\MealEntryRepository;
use App\Repository\NotificationLogRepository;
use App\Service\InAppNotifier;
use App\Service\LocaleFormatter;
use App\Outbound\OutboundSender;
use App\Outbound\Reachability;
use App\Service\NotificationService;
use App\Service\SubscriptionService;
use App\Service\SupplementRecommendationService;
use App\Service\UserTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Notifier\TexterInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A failed digest email must not skip the cooldown log. The in-app notices are
 * created before the email goes out, so without the log the next hourly run
 * re-creates them — duplicate bell notices for the same day.
 */
final class NotificationServiceEmailFailureTest extends TestCase
{
    /** An instant that is 19:30 in the test user's America/New_York evening. */
    private const NOW = '2026-07-21 23:30:00+00:00';

    public function testMailFailureStillWritesTheCooldownLog(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException(new TransportException('SMTP down'));

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->expects(self::once())->method('flush');

        $user = (new User())
            ->setEmail('digest@test.local')
            ->setTimezone('America/New_York')
            ->setNotificationChannel(NotificationChannel::Email)
            ->setEmailVerifiedAt(new \DateTimeImmutable('2026-07-01 00:00:00'))
            ->setNotifyNoMeals(true); // the evening no-meals nudge is the due notice

        $notices = $this->service($mailer, $em)->sendDueNotifications($user, new \DateTimeImmutable(self::NOW));

        self::assertNotSame([], $notices);
        self::assertNotSame([], array_filter($persisted, static fn (object $e): bool => $e instanceof NotificationLog));
    }

    private function service(MailerInterface $mailer, EntityManagerInterface $em): NotificationService
    {
        $meals = $this->createStub(MealEntryRepository::class);
        $meals->method('findByUserAndDateRange')->willReturn([]); // nothing logged today

        $log = $this->createStub(NotificationLogRepository::class);
        $log->method('hasSentSince')->willReturn(false); // no cooldown in effect

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('digest text');

        return new NotificationService(
            $em,
            new OutboundSender($mailer, $this->createStub(TexterInterface::class), new NullLogger()),
            new NullLogger(),
            $this->createStub(HealthMetricRepository::class),
            $meals,
            $this->createStub(DailyIntake::class),
            $log,
            $this->createStub(SupplementRecommendationService::class),
            new UserTime(),
            $this->createStub(InAppNotifier::class),
            $this->createStub(UrlGeneratorInterface::class),
            $translator,
            new LocaleSwitcher('en', []),
            $this->createStub(LocaleFormatter::class),
            new Reachability(new SubscriptionService(new MockClock())),
        );
    }
}

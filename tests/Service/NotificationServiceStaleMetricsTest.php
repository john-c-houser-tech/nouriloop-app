<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
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
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Notifier\TexterInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The stale-metrics notice compares the latest reading (stored as the user's
 * local wall-clock) against the user's local now — not against the server
 * instant, which skewed the 7-day cutoff by the user's offset from the server
 * zone. The server zone is pinned so the skew is deterministic.
 */
final class NotificationServiceStaleMetricsTest extends TestCase
{
    private string $serverZone;

    protected function setUp(): void
    {
        $this->serverZone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->serverZone);
    }

    public function testReadingJustInsideTheWindowIsFreshForAFarWestUser(): void
    {
        // Pago Pago is UTC−11: its wall-clock read as UTC lands 11 h early.
        $user = $this->user('Pacific/Pago_Pago');
        $now = new \DateTimeImmutable('2026-07-21 12:00:00+00:00');
        // 6 days 22 hours ago in the user's own wall-clock — inside the 7-day window.
        $latest = (new UserTime())->now($user, $now)->modify('-6 days -22 hours')->format('Y-m-d H:i:s');

        self::assertSame([], $this->service(new \DateTimeImmutable($latest))->sendDueNotifications($user, $now, true));
    }

    public function testReadingJustOutsideTheWindowIsStaleForAFarEastUser(): void
    {
        // Kiritimati is UTC+14: its wall-clock read as UTC lands 14 h late.
        $user = $this->user('Pacific/Kiritimati');
        $now = new \DateTimeImmutable('2026-07-21 12:00:00+00:00');
        // 7 days 2 hours ago in the user's own wall-clock — outside the window.
        $latest = (new UserTime())->now($user, $now)->modify('-7 days -2 hours')->format('Y-m-d H:i:s');

        self::assertNotSame(
            [],
            $this->service(new \DateTimeImmutable($latest))->sendDueNotifications($user, $now, true),
        );
    }

    private function user(string $timezone): User
    {
        return (new User())
            ->setEmail('stale@test.local')
            ->setTimezone($timezone)
            ->setNotifyStaleMetrics(true); // the only notice turned on
    }

    private function service(\DateTimeImmutable $latest): NotificationService
    {
        $metrics = $this->createStub(HealthMetricRepository::class);
        $metrics->method('findLatestRecordedAt')->willReturn($latest);

        $log = $this->createStub(NotificationLogRepository::class);
        $log->method('hasSentSince')->willReturn(false); // no cooldown in effect

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('digest text');

        return new NotificationService(
            $this->createStub(EntityManagerInterface::class),
            new OutboundSender(
                $this->createStub(MailerInterface::class),
                $this->createStub(TexterInterface::class),
                new NullLogger(),
            ),
            new NullLogger(),
            $metrics,
            $this->createStub(MealEntryRepository::class),
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

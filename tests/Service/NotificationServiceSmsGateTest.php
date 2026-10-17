<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Enum\SubscriptionTier;
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
 * The digest's send-time SMS gate (goal/notification tiering): the SMS channel
 * requires the `sms` entitlement (Loop+ only) because SMS carries real
 * per-message cost — a Free user's digest stays in-app + email only, and the
 * stored channel preference is never mutated. Unit-level with a mocked texter
 * because SMS sends aren't observable through the test profiler the way mail is.
 */
final class NotificationServiceSmsGateTest extends TestCase
{
    /** An instant that is 19:30 in the test user's America/New_York evening. */
    private const NOW = '2026-07-21 23:30:00+00:00';

    public function testFreeUserWithSmsChannelGetsNoDigestSms(): void
    {
        $texter = $this->createMock(TexterInterface::class);
        $texter->expects(self::never())->method('send');

        $notices = $this->service($texter)->sendDueNotifications(
            $this->smsUser(entitled: false),
            new \DateTimeImmutable(self::NOW),
        );

        // The notice itself was due (delivered in-app) — only the SMS was gated.
        self::assertNotSame([], $notices);
    }

    public function testEntitledUserWithSmsChannelGetsDigestSms(): void
    {
        $texter = $this->createMock(TexterInterface::class);
        $texter->expects(self::once())->method('send');

        $notices = $this->service($texter)->sendDueNotifications(
            $this->smsUser(entitled: true),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertNotSame([], $notices);
    }

    public function testLoopUserWithSmsChannelGetsNoDigestSms(): void
    {
        // Loop is the bring-your-own-key plan: its price doesn't cover Twilio.
        $texter = $this->createMock(TexterInterface::class);
        $texter->expects(self::never())->method('send');

        $user = $this->smsUser(entitled: false);
        $user->setCompTier(SubscriptionTier::Loop);
        $this->service($texter)->sendDueNotifications($user, new \DateTimeImmutable(self::NOW));
    }

    public function testEntitledUserWithoutTcpaConsentGetsNoDigestSms(): void
    {
        // Since tiering, a Free user can store the SMS channel without ever seeing
        // the consent controls — so consent must be re-checked at send time, not
        // inferred from the channel preference (Copilot catch on PR #166).
        $texter = $this->createMock(TexterInterface::class);
        $texter->expects(self::never())->method('send');

        $notices = $this->service($texter)->sendDueNotifications(
            $this->smsUser(entitled: true, consented: false),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertNotSame([], $notices);
    }

    private function smsUser(bool $entitled, bool $consented = true): User
    {
        $user = (new User())
            ->setEmail('digest@test.local')
            ->setTimezone('America/New_York')
            ->setNotificationChannel(NotificationChannel::Sms)
            ->setPhoneNumber('+15035551234')
            ->setNotifyNoMeals(true); // the evening no-meals nudge is the due notice
        if ($consented) {
            $user->setSmsConsentAt(new \DateTimeImmutable('2026-07-01 00:00:00'));
        }
        if ($entitled) {
            $user->setCompTier(SubscriptionTier::LoopPlus); // permanent Loop+ comp
        }

        return $user;
    }

    private function service(TexterInterface $texter): NotificationService
    {
        $meals = $this->createStub(MealEntryRepository::class);
        $meals->method('findByUserAndDateRange')->willReturn([]); // nothing logged today

        $log = $this->createStub(NotificationLogRepository::class);
        $log->method('hasSentSince')->willReturn(false); // no cooldown in effect

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('digest text');

        return new NotificationService(
            $this->createStub(EntityManagerInterface::class),
            new OutboundSender($this->createStub(MailerInterface::class), $texter, new NullLogger()),
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

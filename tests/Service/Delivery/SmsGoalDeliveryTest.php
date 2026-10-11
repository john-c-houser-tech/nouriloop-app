<?php

declare(strict_types=1);

namespace App\Tests\Service\Delivery;

use App\Entity\GoalCheckin;
use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalCheckinStatus;
use App\Enum\NotificationChannel;
use App\Enum\SubscriptionTier;
use App\Outbound\OutboundSender;
use App\Outbound\Reachability;
use App\Service\Delivery\SmsGoalDelivery;
use App\Service\SubscriptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;

final class SmsGoalDeliveryTest extends TestCase
{
    public function testSupportsSmsChannelsOnly(): void
    {
        // A stub (not a mock) — this test verifies channel logic, not texter calls.
        $delivery = $this->delivery($this->createStub(TexterInterface::class));

        self::assertTrue($delivery->supports(NotificationChannel::Sms));
        self::assertTrue($delivery->supports(NotificationChannel::Both));
        self::assertFalse($delivery->supports(NotificationChannel::Email));
    }

    public function testSendsSmsWhenPhoneConsentAndEntitlementPresent(): void
    {
        $texter = $this->createMock(TexterInterface::class);
        $texter->expects(self::once())
            ->method('send')
            ->with(self::callback(
                static fn (SmsMessage $m): bool => $m->getPhone() === '+15035551234'
                    && str_contains($m->getSubject(), 'More fiber')
            ));

        $this->delivery($texter)->deliver($this->checkin('+15035551234', consented: true));
    }

    public function testSkipsWhenConsentMissing(): void
    {
        $texter = $this->createMock(TexterInterface::class);
        $texter->expects(self::never())->method('send');

        $this->delivery($texter)->deliver($this->checkin('+15035551234', consented: false));
    }

    public function testSkipsWhenPhoneMissing(): void
    {
        $texter = $this->createMock(TexterInterface::class);
        $texter->expects(self::never())->method('send');

        $this->delivery($texter)->deliver($this->checkin(null, consented: true));
    }

    public function testSkipsUserWithoutCheckinDelivery(): void
    {
        // Phone + consent present, but no Loop+ entitlement — check-in SMS is
        // gated on checkin_delivery (goal tiering), so nothing sends.
        $texter = $this->createMock(TexterInterface::class);
        $texter->expects(self::never())->method('send');

        $this->delivery($texter)->deliver(
            $this->checkin('+15035551234', consented: true, entitled: false),
        );
    }

    private function delivery(TexterInterface $texter): SmsGoalDelivery
    {
        return new SmsGoalDelivery(
            new OutboundSender($this->createStub(MailerInterface::class), $texter, new NullLogger()),
            new Reachability(new SubscriptionService(new MockClock())),
            self::enTranslator(),
        );
    }

    private function checkin(?string $phone, bool $consented, bool $entitled = true): GoalCheckin
    {
        $user = new User();
        $user->setEmail('goal@test.local');
        // The dispatcher only hands SMS-channel users to this delivery.
        $user->setNotificationChannel(NotificationChannel::Sms);
        if ($phone !== null) {
            $user->setPhoneNumber($phone);
        }
        if ($consented) {
            $user->setSmsConsentAt(new \DateTimeImmutable());
        }
        if ($entitled) {
            $user->setCompTier(SubscriptionTier::LoopPlus); // permanent comp → Loop+
        }

        $goal = new NutritionGoal();
        $goal->setName('More fiber');
        $goal->setUser($user);

        $checkin = new GoalCheckin();
        $checkin->setGoal($goal);
        $checkin->setStatus(GoalCheckinStatus::OnTrack);

        return $checkin;
    }

    private static function enTranslator(): \Symfony\Component\Translation\Translator
    {
        $translator = new \Symfony\Component\Translation\Translator('en');
        $translator->addLoader('yaml', new \Symfony\Component\Translation\Loader\YamlFileLoader());
        $translator->addResource(
            'yaml',
            __DIR__ . '/../../../translations/messages+intl-icu.en.yaml',
            'en',
            'messages+intl-icu',
        );

        return $translator;
    }
}

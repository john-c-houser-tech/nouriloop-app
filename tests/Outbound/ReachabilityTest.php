<?php

declare(strict_types=1);

namespace App\Tests\Outbound;

use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Enum\SubscriptionTier;
use App\Outbound\MessagePurpose;
use App\Outbound\OutboundChannel;
use App\Outbound\Reachability;
use App\Service\SubscriptionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ReachabilityTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, OutboundChannel, MessagePurpose, bool}>
     */
    public static function cases(): iterable
    {
        $ready = [
            'channel' => NotificationChannel::Both,
            'verified' => true,
            'phone' => '+15035551234',
            'consent' => true,
            'tier' => SubscriptionTier::LoopPlus,
        ];
        $email = OutboundChannel::Email;
        $sms = OutboundChannel::Sms;
        $digest = MessagePurpose::Digest;
        $checkIn = MessagePurpose::CheckIn;
        $loop = SubscriptionTier::Loop;
        $smsOnly = ['channel' => NotificationChannel::Sms];
        $emailOnly = ['channel' => NotificationChannel::Email];

        // Email
        yield 'digest email, free, verified' => [['tier' => null] + $ready, $email, $digest, true];
        yield 'digest email, unverified' => [['verified' => false] + $ready, $email, $digest, false];
        yield 'digest email, SMS-only choice' => [$smsOnly + $ready, $email, $digest, false];
        yield 'check-in email, Loop+' => [$ready, $email, $checkIn, true];
        yield 'check-in email, Loop' => [['tier' => $loop] + $ready, $email, $checkIn, true];
        yield 'check-in email, free' => [['tier' => null] + $ready, $email, $checkIn, false];

        // SMS — Loop+ only since the tier rework (Twilio fees; ADR 0003)
        yield 'digest SMS, Loop+' => [$ready, $sms, $digest, true];
        yield 'digest SMS, Loop' => [['tier' => $loop] + $ready, $sms, $digest, false];
        yield 'digest SMS, free' => [['tier' => null] + $ready, $sms, $digest, false];
        yield 'digest SMS, no consent' => [['consent' => false] + $ready, $sms, $digest, false];
        yield 'digest SMS, no phone' => [['phone' => null] + $ready, $sms, $digest, false];
        yield 'check-in SMS, empty phone' => [['phone' => ''] + $ready, $sms, $checkIn, false];
        yield 'check-in SMS, Loop+' => [$ready, $sms, $checkIn, true];
        yield 'check-in SMS, Loop' => [['tier' => $loop] + $ready, $sms, $checkIn, false];
        yield 'check-in SMS, email-only choice' => [$emailOnly + $ready, $sms, $checkIn, false];
    }

    /** @param array<string, mixed> $state */
    #[DataProvider('cases')]
    public function testWhoCanBeReached(
        array $state,
        OutboundChannel $channel,
        MessagePurpose $purpose,
        bool $expected,
    ): void {
        $user = new User();
        $user->setNotificationChannel($state['channel']);
        if ($state['verified']) {
            $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        }
        $user->setPhoneNumber($state['phone']);
        $user->setSmsConsentAt($state['consent'] ? new \DateTimeImmutable('-1 day') : null);
        if ($state['tier'] !== null) {
            $user->setCompTier($state['tier']);
        }

        $reachability = new Reachability(new SubscriptionService(new MockClock()));

        self::assertSame($expected, $reachability->canReach($user, $channel, $purpose));
    }

    public function testSmsMissingNamesWhatIsStillNeeded(): void
    {
        self::assertSame([], Reachability::smsMissing('+15035551234', true));
        self::assertSame([Reachability::MISSING_PHONE], Reachability::smsMissing('  ', true));
        self::assertSame([Reachability::MISSING_CONSENT], Reachability::smsMissing('+15035551234', false));
        self::assertSame(
            [Reachability::MISSING_PHONE, Reachability::MISSING_CONSENT],
            Reachability::smsMissing(null, false),
        );
    }
}

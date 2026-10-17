<?php

declare(strict_types=1);

namespace App\Tests\Service\Delivery;

use App\Dto\GoalDefinition;
use App\Entity\GoalCheckin;
use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalAggregation;
use App\Enum\GoalDirection;
use App\Enum\GoalMetric;
use App\Enum\SubscriptionTier;
use App\Outbound\OutboundSender;
use App\Outbound\Reachability;
use App\Service\Delivery\EmailGoalDelivery;
use App\Service\SubscriptionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Notifier\TexterInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The two send-time gates on check-in email: the `checkin_delivery` entitlement
 * (either paid plan — a Free owner gets in-app only), and the
 * verified-address rule (same as the notifications digest). The in-app delivery
 * is a separate transport and unaffected by either.
 */
final class EmailGoalDeliveryTest extends TestCase
{
    public function testUnverifiedUserGetsNoCheckinEmail(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->delivery($mailer)->deliver($this->checkin(emailVerified: false, entitled: true));
    }

    public function testVerifiedEntitledUserGetsCheckinEmail(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $this->delivery($mailer)->deliver($this->checkin(emailVerified: true, entitled: true));
    }

    public function testAFailedSendIsAWarningNotAnException(): void
    {
        // The dispatcher used to catch it and log an error; a failed outbound
        // message is an expected, retry-later event.
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \RuntimeException('relay down'));

        $this->delivery($mailer)->deliver($this->checkin(emailVerified: true, entitled: true));

        $this->addToAssertionCount(1); // no exception escaped
    }

    public function testUserWithoutCheckinDeliveryGetsNoEmail(): void
    {
        // Verified, but Free — email push of check-ins needs a paid plan.
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->delivery($mailer)->deliver($this->checkin(emailVerified: true, entitled: false));
    }

    public function testLoopUserGetsCheckinEmailToo(): void
    {
        // Both paid plans are full-featured since the tier rework; only SMS is Loop+.
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $checkin = $this->checkin(emailVerified: true, entitled: false);
        $checkin->getGoal()?->getUser()?->setCompTier(SubscriptionTier::Loop);

        $this->delivery($mailer)->deliver($checkin);
    }

    private function checkin(bool $emailVerified, bool $entitled): GoalCheckin
    {
        $user = (new User())
            ->setEmail('goalie@test.local')
            ->setDisplayName('Goalie')
            ->setEmailVerifiedAt($emailVerified ? new \DateTimeImmutable() : null);
        if ($entitled) {
            $user->setCompTier(SubscriptionTier::LoopPlus); // permanent comp → Loop+
        }
        $goal = (new NutritionGoal())
            ->setUser($user)
            ->setName('Fiber intake')
            ->setDefinition(new GoalDefinition(
                GoalMetric::Fiber,
                GoalDirection::AtLeast,
                GoalAggregation::DailyAverage,
                28.0,
                7,
            ));

        return (new GoalCheckin())->setGoal($goal);
    }

    private function delivery(MailerInterface $mailer): EmailGoalDelivery
    {
        $urlGen = $this->createStub(UrlGeneratorInterface::class);
        $urlGen->method('generate')->willReturn('https://nouriloop.com/goals/1');

        return new EmailGoalDelivery(
            new OutboundSender($mailer, $this->createStub(TexterInterface::class), new NullLogger()),
            $urlGen,
            sys_get_temp_dir(),
            self::enTranslator(),
            new Reachability(new SubscriptionService(new MockClock())),
        );
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

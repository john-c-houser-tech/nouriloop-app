<?php

declare(strict_types=1);

namespace App\Tests\Service\Delivery;

use App\Entity\GoalCheckin;
use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalCheckinStatus;
use App\Enum\NotificationCategory;
use App\Service\Delivery\InAppGoalDelivery;
use App\Service\InAppNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InAppGoalDeliveryTest extends TestCase
{
    public function testDeliverableCheckinCreatesAGoalNotification(): void
    {
        $user = new User();
        $goal = (new NutritionGoal())->setUser($user)->setName('Fiber intake');
        $checkin = (new GoalCheckin())
            ->setGoal($goal)
            ->setStatus(GoalCheckinStatus::OnTrack)
            ->setSummaryText('You averaged 28g of fiber a day — right on target.');

        $urlGen = $this->createStub(UrlGeneratorInterface::class);
        $urlGen->method('generate')->willReturn('/goals/12');

        $notifier = $this->createMock(InAppNotifier::class);
        $notifier->expects(self::once())
            ->method('notify')
            ->with(
                self::identicalTo($user),
                self::identicalTo(NotificationCategory::GoalCheckin),
                self::stringContains('Fiber intake'),
                self::stringContains('fiber'),
                self::identicalTo('/goals/12'),
                self::anything(),
            );

        (new InAppGoalDelivery($notifier, $urlGen, new NullLogger(), self::enTranslator()))->deliver($checkin);
    }

    public function testTitleCarriesTheStatusLabel(): void
    {
        $user = new User();
        $goal = (new NutritionGoal())->setUser($user)->setName('Protein');
        $checkin = (new GoalCheckin())->setGoal($goal)->setStatus(GoalCheckinStatus::Achieved);

        $urlGen = $this->createStub(UrlGeneratorInterface::class);
        $urlGen->method('generate')->willReturn('/goals/3');

        $notifier = $this->createMock(InAppNotifier::class);
        $notifier->expects(self::once())
            ->method('notify')
            ->with(self::anything(), self::anything(), self::stringContains('Goal achieved'));

        (new InAppGoalDelivery($notifier, $urlGen, new NullLogger(), self::enTranslator()))->deliver($checkin);
    }

    public function testNoDataCheckinDoesNotPingTheBell(): void
    {
        $user = new User();
        $goal = (new NutritionGoal())->setUser($user)->setName('Sodium');
        $checkin = (new GoalCheckin())->setGoal($goal)->setStatus(GoalCheckinStatus::NoData);

        $notifier = $this->createMock(InAppNotifier::class);
        $notifier->expects(self::never())->method('notify');

        $delivery = new InAppGoalDelivery(
            $notifier,
            $this->createStub(UrlGeneratorInterface::class),
            new NullLogger(),
            self::enTranslator(),
        );
        $delivery->deliver($checkin);
    }

    public function testErrorCheckinDoesNotPingTheBell(): void
    {
        $user = new User();
        $goal = (new NutritionGoal())->setUser($user)->setName('Sugar');
        $checkin = (new GoalCheckin())->setGoal($goal)->setStatus(GoalCheckinStatus::Error);

        $notifier = $this->createMock(InAppNotifier::class);
        $notifier->expects(self::never())->method('notify');

        $delivery = new InAppGoalDelivery(
            $notifier,
            $this->createStub(UrlGeneratorInterface::class),
            new NullLogger(),
            self::enTranslator(),
        );
        $delivery->deliver($checkin);
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

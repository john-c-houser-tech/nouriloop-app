<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RunGoalCheckinsCommand;
use App\Entity\GoalCheckin;
use App\Entity\NutritionGoal;
use App\Enum\GoalCheckinStatus;
use App\Repository\NutritionGoalRepository;
use App\Service\CheckinAllowance;
use App\Service\Delivery\GoalDeliveryDispatcher;
use App\Service\GoalEvaluationService;
use App\Service\GoalScheduler;
use App\Service\InAppNotifier;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Per-goal failure isolation: one goal's exception must not block the rest of the
 * hourly batch, and the run must still fail loudly.
 */
final class RunGoalCheckinsCommandTest extends TestCase
{
    public function testOneFailingGoalDoesNotStopTheRestAndFailsTheRun(): void
    {
        $broken = (new NutritionGoal())->setName('Broken goal');
        $fine = (new NutritionGoal())->setName('Fine goal');

        $goals = $this->createStub(NutritionGoalRepository::class);
        $goals->method('findDue')->willReturn([$broken, $fine]);

        $evaluated = [];
        $evaluator = $this->createStub(GoalEvaluationService::class);
        $evaluator->method('evaluate')->willReturnCallback(
            static function (NutritionGoal $goal) use ($broken, &$evaluated): GoalCheckin {
                if ($goal === $broken) {
                    throw new \RuntimeException('chart render failed');
                }
                $evaluated[] = $goal->getName();

                return (new GoalCheckin())->setGoal($goal)->setStatus(GoalCheckinStatus::NoData);
            },
        );

        $scheduler = $this->createStub(GoalScheduler::class);
        $scheduler->method('computeNextRun')->willReturn(new \DateTimeImmutable('2026-07-28 18:00:00'));

        $command = new RunGoalCheckinsCommand(
            $goals,
            $evaluator,
            $scheduler,
            $this->createStub(GoalDeliveryDispatcher::class),
            $this->createStub(EntityManagerInterface::class),
            new SubscriptionService(new MockClock()),
            $this->createStub(CheckinAllowance::class),
            $this->createStub(InAppNotifier::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(UrlGeneratorInterface::class),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(['Fine goal'], $evaluated);
        // The goal after the failing one was still evaluated AND rescheduled.
        self::assertEquals(new \DateTimeImmutable('2026-07-28 18:00:00'), $fine->getNextRunAt());
        self::assertStringContainsString('chart render failed', $tester->getDisplay());
        self::assertStringContainsString('1 failed', $tester->getDisplay());
    }
}

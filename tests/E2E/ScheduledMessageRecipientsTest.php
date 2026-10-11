<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Dto\GoalDefinition;
use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalAggregation;
use App\Enum\GoalDirection;
use App\Enum\GoalFrequency;
use App\Enum\GoalMetric;
use App\Repository\NutritionGoalRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Who the scheduled digest and goal check-ins run for: enabled accounts without a
 * pending deletion. Until 2026-10-07 an account an admin disabled (which can't
 * even sign in) still got both.
 */
final class ScheduledMessageRecipientsTest extends WebTestCase
{
    use DbResetTrait;

    public function testOnlyEnabledAccountsWithoutAPendingDeletionGetScheduledMessages(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $active = $this->user($em, 'active@test.local');
        $disabled = $this->user($em, 'disabled@test.local')->setActive(false);
        $leaving = $this->user($em, 'leaving@test.local')->setDeletionRequestedAt(new \DateTimeImmutable('-1 day'));
        foreach ([$active, $disabled, $leaving] as $user) {
            $this->dueGoal($em, $user);
        }
        $em->flush();

        $digest = static::getContainer()->get(UserRepository::class)->findReceivingScheduledMessages();
        self::assertSame(
            ['active@test.local'],
            array_map(static fn (User $u): string => (string) $u->getEmail(), $digest),
        );

        $due = static::getContainer()->get(NutritionGoalRepository::class)->findDue(new \DateTimeImmutable());
        self::assertSame(
            ['active@test.local'],
            array_map(static fn (NutritionGoal $g): string => (string) $g->getUser()?->getEmail(), $due),
        );
    }

    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName('Recipient')
            ->setRoles(['ROLE_USER'])
            ->setPassword('unused');
        $em->persist($user);

        return $user;
    }

    private function dueGoal(EntityManagerInterface $em, User $user): void
    {
        $em->persist((new NutritionGoal())
            ->setUser($user)
            ->setName('Eat more fiber')
            ->setDefinition(
                new GoalDefinition(GoalMetric::Fiber, GoalDirection::AtLeast, GoalAggregation::DailyAverage, 28.0, 7),
            )
            ->setEvaluationPromptTemplate('Evaluate the goal.')
            ->setFrequency(GoalFrequency::Weekly)
            ->setDayOfWeek(7)
            ->setNextRunAt(new \DateTimeImmutable('-1 hour')));
    }
}

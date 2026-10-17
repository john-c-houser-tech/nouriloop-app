<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GoalDefinition;
use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalFrequency;
use App\Enum\GoalOutputFormat;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds and persists a NutritionGoal from a confirmed definition + schedule +
 * delivery preferences. Shared by both creation paths — the structured form and
 * the goal-setting conversation — so they produce identical records (default
 * prompt template, captured baseline, computed next-run time).
 */
class GoalFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GoalScheduler $scheduler,
        private readonly GoalPromptBuilder $prompts,
        private readonly GoalMetricCalculator $calculator,
        private readonly OnboardingService $onboarding,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    public function create(
        User $user,
        GoalDefinition $definition,
        string $name,
        GoalFrequency $frequency,
        ?int $dayOfWeek,
        int $hour,
        int $minute,
        GoalOutputFormat $outputFormat,
    ): NutritionGoal {
        // Server-side backstop for the daily-cadence entitlement (paid plans): the
        // form disables the option and the chat card shows a note, but every
        // creation path funnels through here, so a tampered POST or a raw daily
        // proposal is coerced to weekly rather than trusted.
        if ($frequency === GoalFrequency::Daily && !$this->subscriptions->hasFeature($user, 'daily_checkins')) {
            $frequency = GoalFrequency::Weekly;
            $dayOfWeek ??= 7;
        }

        // Capture the user's current standing if the caller didn't already — over the
        // goal's own window and aggregation, so the baseline is comparable to the target.
        if ($definition->baselineValue === null) {
            $definition = $definition->withBaseline($this->calculator->baseline(
                $user,
                $definition->metric,
                $definition->evaluationWindowDays,
                $definition->aggregation,
            ));
        }

        $name = trim($name);
        if ($name === '') {
            $name = ucfirst($definition->summary());
        }

        $goal = (new NutritionGoal())
            ->setUser($user)
            ->setName(mb_substr($name, 0, 120))
            ->setDefinition($definition)
            ->setEvaluationPromptTemplate($this->prompts->defaultTemplate($definition))
            ->setFrequency($frequency)
            ->setHour($hour)
            ->setMinute($minute)
            ->setOutputFormat($outputFormat)
            ->setDayOfWeek($frequency === GoalFrequency::Weekly ? ($dayOfWeek ?? 7) : null);

        $goal->setNextRunAt($this->scheduler->computeNextRun($goal, new \DateTimeImmutable()));

        $this->em->persist($goal);
        $this->em->flush();

        // First goal created → advance onboarding step 2 (no-op once onboarding is done).
        $this->onboarding->markStep($user, 'goal');

        return $goal;
    }
}

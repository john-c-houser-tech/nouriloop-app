<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalFrequency;
use App\Enum\NotificationCategory;
use App\Repository\NutritionGoalRepository;
use App\Service\CheckinAllowance;
use App\Service\Delivery\GoalDeliveryDispatcher;
use App\Service\GoalEvaluationService;
use App\Service\GoalScheduler;
use App\Service\InAppNotifier;
use App\Service\SubscriptionService;
use App\Support\NowOption;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Runs each due goal's saved prompt against the user's logged meals and records a
 * check-in. Cron-driven (hourly), so it's restart-safe: a goal is "due" purely from
 * its persisted nextRunAt, and the next run is recomputed after each evaluation.
 *
 * Tier enforcement lives HERE, per goal, before evaluate() — not only at creation —
 * because entitlements downgrade lazily (a lapsed Loop+ subscriber's daily goal must
 * quietly drop to weekly + in-app; the same mechanism handles beta-comp expiries):
 *  - daily goal, owner not entitled to `daily_checkins` → persist-migrated to weekly
 *    (one-time in-app notice), then evaluated and rescheduled weekly;
 *  - Free owner with the lifetime check-in allowance exhausted → evaluation skipped,
 *    the goal is paused (isActive=false + nextRunAt cleared — the same state as the
 *    goal page's Pause button, so the existing Resume toggle is the resume path) and
 *    a one-time "check-ins paused" in-app notice is written. Nothing is deleted; the
 *    goal and its history stay fully visible.
 *
 * Cron (hourly): 0 * * * * cd /path/to/project && php bin/console app:run-goal-checkins
 */
#[AsCommand(
    name: 'app:run-goal-checkins',
    description: 'Evaluate due nutrition goals against logged meals and record progress check-ins',
)]
class RunGoalCheckinsCommand extends Command
{
    public function __construct(
        private readonly NutritionGoalRepository $goals,
        private readonly GoalEvaluationService $evaluator,
        private readonly GoalScheduler $scheduler,
        private readonly GoalDeliveryDispatcher $dispatcher,
        private readonly EntityManagerInterface $em,
        private readonly SubscriptionService $subscriptions,
        private readonly CheckinAllowance $allowance,
        private readonly InAppNotifier $notifier,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only; skip Claude, persist, reschedule')
            ->addOption('goal', null, InputOption::VALUE_REQUIRED, 'Evaluate one goal by id, even if it is not due')
            ->addOption('now', null, InputOption::VALUE_REQUIRED, 'Evaluate as if it were this datetime (for testing)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $now = NowOption::parse($input->getOption('now'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $goalId = $input->getOption('goal');
        if ($goalId !== null) {
            $goal = $this->goals->find((int) $goalId);
            if ($goal === null) {
                $io->error(sprintf('No goal with id %s.', $goalId));

                return Command::INVALID;
            }
            $due = [$goal];
        } else {
            $due = $this->goals->findDue($now);
        }

        if ($due === []) {
            $io->success('No goals are due.');

            return Command::SUCCESS;
        }

        $rows = [];
        $failed = 0;
        foreach ($due as $goal) {
            try {
                $rows[] = $this->checkIn($goal, $now, $dryRun);
            } catch (\Throwable $e) {
                // One goal's failure (DB, chart render, …) must not block the rest
                // of the batch. A failure before the flush leaves nextRunAt as it
                // was, so that goal retries next hour.
                $failed++;
                $io->warning(sprintf('Failed to check in goal %d: %s', (int) $goal->getId(), $e->getMessage()));
            }
        }

        $io->table(['User', 'Goal', 'Status', 'Value', 'Next run'], $rows);
        if ($failed > 0) {
            // Fail the run so cron/monitoring notices.
            $io->error(sprintf('Checked in %d goal(s); %d failed.', count($rows), $failed));

            return Command::FAILURE;
        }

        $io->success(sprintf('%s %d goal(s).', $dryRun ? 'Evaluated (dry-run)' : 'Checked in', count($rows)));

        return Command::SUCCESS;
    }

    /**
     * Evaluate (or pause/migrate) one due goal and return its report row.
     *
     * @return list<string>
     */
    private function checkIn(NutritionGoal $goal, \DateTimeImmutable $now, bool $dryRun): array
    {
        $owner = $goal->getUser();

        // Free allowance exhausted → pause instead of evaluating (no Claude
        // call). Checked per goal against the current count, so the run that
        // consumes the last included check-in still completes normally. The
        // pause + notice fire only while the goal is still active: findDue()
        // never returns paused goals, but --goal bypasses it, and a forced
        // re-run must not stack duplicate notices (Copilot catch on #166).
        if ($owner !== null && !$this->allowance->isUnlimited($owner) && $this->allowance->isExhausted($owner)) {
            if (!$dryRun && $goal->isActive()) {
                $this->pauseForAllowance($goal, $owner);
            }

            return [
                (string) $owner->getEmail(),
                $goal->getName(),
                $dryRun ? 'Would pause (free allowance used)' : 'Paused (free allowance used)',
                '—',
                '—',
            ];
        }

        // Daily cadence without the Loop+ entitlement → persist-migrate the
        // goal to weekly (one-time notice), then evaluate this run as normal;
        // the reschedule below lands on the new weekly cadence.
        $migrated = false;
        if (
            $owner !== null
            && $goal->getFrequency() === GoalFrequency::Daily
            && !$this->subscriptions->hasFeature($owner, 'daily_checkins')
        ) {
            $migrated = true;
            if (!$dryRun) {
                $this->migrateToWeekly($goal, $owner);
            }
        }

        $checkin = $this->evaluator->evaluate($goal, $now, generateNarrative: !$dryRun);

        if (!$dryRun) {
            $this->em->persist($checkin);
            $goal->setLastRunAt($now);
            $goal->setNextRunAt($this->scheduler->computeNextRun($goal, $now));
            // Commit the check-in + advanced nextRunAt BEFORE the (synchronous,
            // possibly slow/failing) send, so a delivery error can't leave the
            // goal still "due" and re-send on the next run.
            $this->em->flush();

            // Deliver over the goal's channel(s). Skip non-deliverable
            // statuses (no-data) so users aren't nagged.
            if ($checkin->getStatus()->isDeliverable()) {
                $this->dispatcher->dispatch($checkin);
            }
        }

        return [
            (string) $owner?->getEmail(),
            $goal->getName(),
            $checkin->getStatus()->label() . ($migrated ? ' (migrated to weekly)' : ''),
            $checkin->getCurrentValue() !== null
                ? round($checkin->getCurrentValue(), 1) . ' ' . $goal->getDefinition()->unit()
                : '—',
            $dryRun ? '(dry-run)' : ($goal->getNextRunAt()?->format('M j, g:i A') ?? '—'),
        ];
    }

    /**
     * Pause a goal whose Free owner has used their whole included-check-in
     * allowance: same persisted state as the goal page's Pause button (so the
     * existing Resume toggle re-arms it after an upgrade), plus a one-time in-app
     * notice. One-time by construction — a paused goal never comes due again.
     */
    private function pauseForAllowance(NutritionGoal $goal, User $owner): void
    {
        $goal->setActive(false);
        $goal->setNextRunAt(null);
        $this->em->flush();

        // The cron has no request locale — compose in the OWNER's locale, like the
        // evaluator's deterministic fallback (explicit $locale arg).
        $locale = $owner->getLocale()->value;
        $this->notifier->notify(
            $owner,
            NotificationCategory::GoalCheckin,
            $this->translator->trans(
                'goal.notice.allowance_paused_title',
                ['name' => $goal->getName()],
                null,
                $locale,
            ),
            $this->translator->trans(
                'goal.notice.allowance_paused_body',
                ['limit' => CheckinAllowance::FREE_ALLOWANCE],
                null,
                $locale,
            ),
            $this->urlGenerator->generate('app_goals_show', ['id' => $goal->getId()]),
        );
    }

    /**
     * Persist-migrate a daily goal to weekly for an owner without the Loop+
     * `daily_checkins` entitlement, with a one-time in-app notice. One-time by
     * construction — once weekly, the condition never matches again.
     */
    private function migrateToWeekly(NutritionGoal $goal, User $owner): void
    {
        $goal->setFrequency(GoalFrequency::Weekly);
        $goal->setDayOfWeek($goal->getDayOfWeek() ?? 7);
        $this->em->flush();

        $locale = $owner->getLocale()->value;
        $this->notifier->notify(
            $owner,
            NotificationCategory::GoalCheckin,
            $this->translator->trans(
                'goal.notice.daily_to_weekly_title',
                ['name' => $goal->getName()],
                null,
                $locale,
            ),
            $this->translator->trans('goal.notice.daily_to_weekly_body', [], null, $locale),
            $this->urlGenerator->generate('app_goals_show', ['id' => $goal->getId()]),
        );
    }
}

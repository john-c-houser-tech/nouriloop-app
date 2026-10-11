<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\AccountDeletionService;
use App\Support\NowOption;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Hard-deletes accounts whose self-service deletion grace period
 * (AccountDeletionService::GRACE_DAYS) has elapsed. Run daily by cron — this doesn't
 * need hourly precision like the notification/goal-checkin crons.
 *
 * Cron (daily): 30 3 * * * cd /path/to/project && php bin/console app:purge-deleted-accounts
 */
#[AsCommand(
    name: 'app:purge-deleted-accounts',
    description: 'Hard-delete accounts whose self-service deletion grace period has elapsed',
)]
class PurgeDeletedAccountsCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountDeletionService $accountDeletion,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List matching accounts without deleting')
            ->addOption('now', null, InputOption::VALUE_REQUIRED, 'Evaluate as if it were this datetime (testing)');
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

        $cutoff = $now->modify(sprintf('-%d days', AccountDeletionService::GRACE_DAYS));
        $due = $this->users->findPendingDeletionBefore($cutoff);

        if ($due === []) {
            $io->success('No accounts are due for purge.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Email', 'Requested'],
            array_map(
                static fn ($u) => [(string) $u->getEmail(), $u->getDeletionRequestedAt()?->format('Y-m-d H:i')],
                $due,
            ),
        );

        if ($dryRun) {
            $io->note(sprintf('Dry run — would purge %d account(s). Re-run without --dry-run to apply.', count($due)));

            return Command::SUCCESS;
        }

        $purged = 0;
        $failed = 0;
        foreach ($due as $user) {
            try {
                // Re-read the row right before deleting in case a cancel happened
                // between selection and now — the selected entity is the cached
                // copy, so checking it without a refresh could never see a cancel.
                $this->em->refresh($user);
                if ($user->getDeletionRequestedAt() === null) {
                    $io->note(sprintf('Skipped %s: deletion was canceled.', $user->getEmail()));
                    continue;
                }

                $this->accountDeletion->purgeUser($user);
                $purged++;
            } catch (\Throwable $e) {
                // One bad account shouldn't block the rest of the batch.
                $failed++;
                $io->warning(sprintf('Failed to purge %s: %s', $user->getEmail(), $e->getMessage()));
            }
        }

        if ($failed > 0) {
            // Fail the run so cron/monitoring notices, rather than retrying silently every day.
            $io->error(sprintf('Purged %d of %d account(s); %d failed.', $purged, count($due), $failed));

            return Command::FAILURE;
        }

        $io->success(sprintf('Purged %d of %d account(s).', $purged, count($due)));

        return Command::SUCCESS;
    }
}

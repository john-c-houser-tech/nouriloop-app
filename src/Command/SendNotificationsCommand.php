<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Service\UserTime;
use App\Support\NowOption;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends each user their daily digest at ~8 PM *their local time*. Run hourly by
 * cron: every user whose local hour is SEND_HOUR is evaluated this pass, so users
 * in any timezone get their digest in their own evening. Per-type cooldowns
 * (NotificationLog) keep it to one digest a day even if the hour is hit twice.
 *
 * Cron (hourly): 0 * * * * cd /path/to/project && php bin/console app:send-notifications
 */
#[AsCommand(
    name: 'app:send-notifications',
    description: 'Email users about stale health metrics, calorie extremes, and weekly supplement gaps',
)]
class SendNotificationsCommand extends Command
{
    private const SEND_HOUR = 20;

    public function __construct(
        private readonly UserRepository $users,
        private readonly NotificationService $notifier,
        private readonly UserTime $userTime,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Evaluate and report without sending or logging')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Limit to a single user (by email)')
            ->addOption('now', null, InputOption::VALUE_REQUIRED, 'Evaluate as if it were this datetime (testing)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignore the local send-hour gate (testing)');
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

        $email = $input->getOption('user');
        if ($email !== null) {
            $user = $this->users->findOneBy(['email' => $email]);
            $users = $user !== null ? [$user] : [];
        } else {
            $users = $this->users->findReceivingScheduledMessages();
        }

        if ($users === []) {
            $io->warning('No matching users.');

            return Command::SUCCESS;
        }

        $force = (bool) $input->getOption('force');

        $rows = [];
        $failed = 0;
        foreach ($users as $user) {
            // Only reach a user during their local evening (one hour per day),
            // unless --force is set for testing.
            if (!$force && $this->userTime->localHour($user, $now) !== self::SEND_HOUR) {
                continue;
            }

            try {
                $due = $this->notifier->sendDueNotifications($user, $now, $dryRun);
            } catch (\Throwable $e) {
                // One user's failure must not cost everyone after them their
                // once-a-day evening window.
                $failed++;
                $io->warning(sprintf('Failed to notify %s: %s', $user->getEmail(), $e->getMessage()));
                continue;
            }
            if ($due !== []) {
                $rows[] = [(string) $user->getEmail(), implode(', ', $due)];
            }
        }

        if ($rows !== []) {
            $io->table(['User', $dryRun ? 'Notices (dry-run)' : 'Notices sent'], $rows);
        }

        if ($failed > 0) {
            // Fail the run so cron/monitoring notices.
            $io->error(sprintf('Notified %d of %d user(s); %d failed.', count($rows), count($users), $failed));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s %d of %d user(s).',
            $dryRun ? 'Would notify' : 'Notified',
            count($rows),
            count($users),
        ));

        return Command::SUCCESS;
    }
}

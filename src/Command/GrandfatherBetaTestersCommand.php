<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\SubscriptionTier;
use App\Repository\UserRepository;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-time launch command: grandfather the existing beta cohort with permanent
 * Loop+. Run once when going public — every account that exists at that moment is
 * a beta tester (registration is invite-only) and gets a permanent complimentary
 * Loop+ (no expiry). Idempotent: users who already have a comp are left untouched,
 * so it's safe to re-run and it won't clobber a time-limited beta-invite comp.
 */
#[AsCommand(
    name: 'app:grandfather-beta-testers',
    description: 'Grant permanent Loop+ to all existing users (one-time public-launch grandfathering)',
)]
class GrandfatherBetaTestersCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly SubscriptionService $subscriptions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $granted = 0;
        $skipped = 0;

        foreach ($this->users->findAll() as $user) {
            if ($user->getCompTier() !== null) {
                $skipped++;
                continue;
            }

            if (!$dryRun) {
                // Permanent comp: no expiry.
                $this->subscriptions->grantComp($user, SubscriptionTier::LoopPlus, null);
            }
            $granted++;
            $io->writeln(sprintf('  %s %s', $dryRun ? 'would grant' : 'granted', $user->getEmail()));
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s permanent Loop+ to %d user(s); %d already had a comp (skipped).',
            $dryRun ? '[dry-run] would grant' : 'Granted',
            $granted,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}

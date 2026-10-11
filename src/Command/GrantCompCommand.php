<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use App\Repository\UserRepository;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Support tool: grant (or revoke) a complimentary subscription tier for one user.
 * The CLI sibling of the admin list's Upgrade/Downgrade actions (which step one
 * tier at a time; this can set an exact tier + expiry). Goes through the same
 * SubscriptionService::grantComp path as coupon redemption and grandfathering,
 * so entitlement semantics (comp beats Stripe, lazy expiry) can't drift. The old
 * --ai-coach flag is gone with the per-user entitlement (2026-07-24): the AI
 * Pattern Coach now simply follows the Loop+ tier (plus the global kill switch).
 */
#[AsCommand(
    name: 'app:grant-comp',
    description: 'Grant (or revoke with --revoke) a complimentary tier for a user',
)]
class GrantCompCommand extends Command
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
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The user\'s email address')
            ->addOption(
                'tier',
                null,
                InputOption::VALUE_REQUIRED,
                'Comp tier to grant: loop or loop_plus',
                SubscriptionTier::LoopPlus->value,
            )
            ->addOption(
                'expires',
                null,
                InputOption::VALUE_REQUIRED,
                'Comp expiry — absolute ("2027-07-19") or relative ("+1 year"); omit for a permanent comp',
            )
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Clear any comp instead of granting one')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $revoke = (bool) $input->getOption('revoke');
        $dryRun = (bool) $input->getOption('dry-run');

        $user = $this->users->findOneBy(['email' => $email]);
        if ($user === null) {
            $io->error(sprintf('No user found with email "%s".', $email));

            return Command::FAILURE;
        }

        $io->writeln(sprintf(
            'Before: effective tier <info>%s</info>%s',
            $this->subscriptions->effectiveTier($user)->label(),
            $this->describeComp($user),
        ));

        if ($revoke) {
            // Dry-run reports without touching the entity at all: a mutated-but-
            // unflushed user would print as if done and ride along on any later flush.
            if ($dryRun) {
                $io->success('[dry-run] Would clear the comp. No changes were written.');

                return Command::SUCCESS;
            }
            $this->subscriptions->revokeComp($user);
            $io->writeln('Comp cleared.');
        } else {
            $tier = SubscriptionTier::tryFrom((string) $input->getOption('tier'));
            if ($tier === null || $tier === SubscriptionTier::Free) {
                $io->error(sprintf(
                    'Invalid --tier "%s". Use one of: %s. (To remove a comp, use --revoke.)',
                    (string) $input->getOption('tier'),
                    implode(', ', [SubscriptionTier::Loop->value, SubscriptionTier::LoopPlus->value]),
                ));

                return Command::FAILURE;
            }

            $expiresAt = null;
            $expiresOpt = $input->getOption('expires');
            if ($expiresOpt !== null) {
                // A blank string would silently parse as "now" (an instantly lapsed
                // comp), and PHP normalizes impossible calendar dates (2027-02-30 →
                // 2027-03-02) with only an internal warning — reject both.
                $expiresStr = trim((string) $expiresOpt);
                if ($expiresStr === '') {
                    $io->error('--expires is blank. Omit the option entirely for a permanent comp.');

                    return Command::FAILURE;
                }
                try {
                    $expiresAt = new \DateTimeImmutable($expiresStr);
                } catch (\Exception) {
                    $io->error(sprintf('Could not parse --expires "%s" as a date.', $expiresStr));

                    return Command::FAILURE;
                }
                $parseErrors = \DateTimeImmutable::getLastErrors();
                if ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0)) {
                    $io->error(sprintf(
                        '--expires "%s" is not a real calendar date (it would silently become %s).',
                        $expiresStr,
                        $expiresAt->format('Y-m-d'),
                    ));

                    return Command::FAILURE;
                }
                if ($expiresAt <= new \DateTimeImmutable()) {
                    $io->warning(sprintf(
                        '--expires resolves to %s, which is in the past — '
                            . 'the comp will be granted but is already lapsed.',
                        $expiresAt->format('Y-m-d H:i'),
                    ));
                }
            }

            if ($user->getCompTier() !== null) {
                $io->note('Replacing an existing comp (see "Before" above).');
            }

            if ($dryRun) {
                $io->success(sprintf(
                    '[dry-run] Would grant complimentary %s%s. No changes were written.',
                    $tier->label(),
                    $expiresAt === null ? ' (permanent)' : ' until ' . $expiresAt->format('Y-m-d H:i'),
                ));

                return Command::SUCCESS;
            }

            $this->subscriptions->grantComp($user, $tier, $expiresAt);
            $io->writeln(sprintf(
                'Granted complimentary <info>%s</info>%s.',
                $tier->label(),
                $expiresAt === null ? ' (permanent)' : ' until ' . $expiresAt->format('Y-m-d H:i'),
            ));
        }

        $this->em->flush();

        $io->success(sprintf(
            '%s: effective tier %s%s.',
            $email,
            $this->subscriptions->effectiveTier($user)->label(),
            $this->describeComp($user),
        ));

        return Command::SUCCESS;
    }

    private function describeComp(User $user): string
    {
        if ($user->getCompTier() === null) {
            return ' (no comp)';
        }

        $expiresAt = $user->getCompExpiresAt();

        return sprintf(
            ' (comp %s, %s)',
            $user->getCompTier()->label(),
            $expiresAt === null ? 'permanent' : 'expires ' . $expiresAt->format('Y-m-d'),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\HealthMetricRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repairs implausibly long sleep nights for one user.
 *
 * Sleep is stored as per-segment rows in minutes (see HealthMetricType / the
 * importers); HealthMetricRepository::getSleepNightlyData() groups them into
 * nights on a noon boundary and sums by tier priority (stages → asleep → in-bed).
 * Early imports with the wrong settings can leave nights summing to 10h+.
 *
 * This command computes the user's average nightly hours from the *other* nights
 * (those below the threshold) and, for each over-threshold night, deletes that
 * night's sleep segments and writes a single corrected `sleep_asleep` row equal to
 * the average — which is exactly what the nightly aggregation reads back.
 */
#[AsCommand(
    name: 'app:fix-sleep-outliers',
    description: 'Replace implausibly long sleep nights with the user\'s average from all other nights',
)]
class FixSleepOutliersCommand extends Command
{
    /** Sleep segment metric types that feed the nightly total. */
    private const SLEEP_TYPES = ['sleep_in_bed', 'sleep_asleep', 'sleep_core', 'sleep_deep', 'sleep_rem'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly HealthMetricRepository $healthMetrics,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The user whose sleep data to repair')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Nights >= this many hours get fixed', '10')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $threshold = (float) $input->getOption('threshold');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($threshold <= 0) {
            $io->error('--threshold must be a positive number of hours.');

            return Command::FAILURE;
        }

        $user = $this->users->findOneBy(['email' => $email]);
        if ($user === null) {
            $io->error(sprintf('No user found with email "%s".', $email));

            return Command::FAILURE;
        }

        // All nights on record (the repo already buckets + sums by tier priority).
        $from = new \DateTimeImmutable('2000-01-01 00:00:00');
        $to = new \DateTimeImmutable('tomorrow');
        $nights = $this->healthMetrics->getSleepNightlyData($user, $from, $to);

        if ($nights === []) {
            $io->warning('This user has no sleep data — nothing to do.');

            return Command::SUCCESS;
        }

        $isBad = static fn (array $n): bool => (float) $n['total_hours'] >= $threshold;
        $bad = array_values(array_filter($nights, $isBad));
        $good = array_values(array_filter($nights, static fn (array $n): bool => !$isBad($n)));

        if ($bad === []) {
            $io->success(sprintf('No nights at or above %.1f h — nothing to correct.', $threshold));

            return Command::SUCCESS;
        }

        if ($good === []) {
            $io->error(sprintf(
                'Every night is at or above %.1f h, so there is no "normal" night to average. '
                . 'Raise --threshold and try again.',
                $threshold
            ));

            return Command::FAILURE;
        }

        $goodHours = array_map(static fn (array $n): float => (float) $n['total_hours'], $good);
        $avgHours = array_sum($goodHours) / count($goodHours);
        $avgMinutes = round($avgHours * 60, 2);

        $io->section(sprintf(
            'User %s — average from %d normal night(s): %.2f h (%.0f min)',
            $email,
            count($good),
            $avgHours,
            $avgMinutes
        ));

        $io->table(
            ['Night', 'Current hours', 'Corrected hours'],
            array_map(
                static fn (array $n): array => [
                    $n['sleep_date'],
                    sprintf('%.2f', (float) $n['total_hours']),
                    sprintf('%.2f', $avgHours),
                ],
                $bad
            )
        );

        if ($dryRun) {
            $io->note(sprintf('Dry run — would correct %d night(s). Re-run without --dry-run to apply.', count($bad)));

            return Command::SUCCESS;
        }

        $this->connection->beginTransaction();
        try {
            foreach ($bad as $night) {
                // Delete every sleep segment in this night's noon-bucket, then write one
                // corrected `sleep_asleep` row at the night's onset so the aggregation
                // (stages → asleep → in-bed) reads back exactly the average.
                $this->connection->executeStatement(
                    'DELETE FROM health_metric
                      WHERE user_id = :user
                        AND metric_type IN (:types)
                        AND DATE(recorded_at - INTERVAL 12 HOUR) = :night',
                    ['user' => $user->getId(), 'types' => self::SLEEP_TYPES, 'night' => $night['sleep_date']],
                    ['types' => ArrayParameterType::STRING]
                );

                $this->connection->insert('health_metric', [
                    'user_id'     => $user->getId(),
                    'metric_type' => 'sleep_asleep',
                    'value'       => $avgMinutes,
                    'recorded_at' => $night['onset_time'],
                    'source'      => 'corrected-avg',
                ]);
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $io->error('Failed, rolled back: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Corrected %d night(s) to %.2f h each.', count($bad), $avgHours));

        return Command::SUCCESS;
    }
}

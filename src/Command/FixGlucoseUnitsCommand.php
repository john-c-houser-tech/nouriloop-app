<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\HealthUnitNormalizer;
use App\Service\UnitConverter;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repairs blood-glucose rows stored in mmol/L as if they were mg/dL.
 *
 * Until 2026-09-30 the ingestion API and the Apple Health XML importer converted
 * only lb, so a mmol/L reading (e.g. 5.4) was stored verbatim as "5.4 mg/dL".
 * Such rows are unambiguous: real mg/dL readings sit at roughly 40–600, mmol/L
 * readings at roughly 2–33, so anything below
 * HealthUnitNormalizer::GLUCOSE_MMOL_CEILING can only be mmol/L.
 *
 * Reports by default; --apply multiplies the suspect rows by 18.0182. The lower
 * bound keeps --apply idempotent: a converted row always lands at or above the
 * ceiling, so a second run finds nothing. Values below it (under ~35 mg/dL even
 * as mmol/L — below any CGM's range) are listed but never touched.
 */
#[AsCommand(
    name: 'app:fix-glucose-units',
    description: 'Report (and with --apply, convert) glucose rows stored in mmol/L as if mg/dL',
)]
class FixGlucoseUnitsCommand extends Command
{
    /** Smallest mmol/L value whose conversion clears the ceiling (35 / 18.0182 ≈ 1.94). */
    public const MIN_CONVERTIBLE = 1.95;

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Limit to one user (by email)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Convert the rows (default: report only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        $userId = null;
        $email = $input->getOption('user');
        if ($email !== null) {
            $userId = $this->connection->fetchOne('SELECT id FROM `user` WHERE email = ?', [$email]);
            if ($userId === false) {
                $io->error(sprintf('No user found with email "%s".', $email));

                return Command::FAILURE;
            }
        }

        [$userSql, $userParams] = $userId !== null ? [' AND h.user_id = ?', [(int) $userId]] : ['', []];
        $params = [self::MIN_CONVERTIBLE, HealthUnitNormalizer::GLUCOSE_MMOL_CEILING, ...$userParams];

        $rows = $this->connection->fetchAllAssociative(
            "SELECT u.email, COUNT(*) AS n, MIN(h.value) AS min_value, MAX(h.value) AS max_value,
                    GROUP_CONCAT(DISTINCT h.source ORDER BY h.source SEPARATOR ', ') AS sources
             FROM health_metric h JOIN `user` u ON u.id = h.user_id
             WHERE h.metric_type = 'blood_glucose' AND h.value >= ? AND h.value < ?{$userSql}
             GROUP BY u.email ORDER BY n DESC",
            $params,
        );

        $tooLow = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM health_metric h
             WHERE h.metric_type = 'blood_glucose' AND h.value < ?{$userSql}",
            [self::MIN_CONVERTIBLE, ...$userParams],
        );

        if ($tooLow > 0) {
            $io->note(sprintf(
                '%d glucose row(s) below %.2f are implausible in either unit and are left alone.',
                $tooLow,
                self::MIN_CONVERTIBLE,
            ));
        }

        if ($rows === []) {
            $io->success('No glucose rows look like mmol/L.');

            return Command::SUCCESS;
        }

        $io->table(
            ['User', 'Rows', 'Min', 'Max', 'Sources'],
            array_map(static fn (array $r): array => [
                $r['email'],
                $r['n'],
                round((float) $r['min_value'], 2),
                round((float) $r['max_value'], 2),
                $r['sources'],
            ], $rows),
        );
        $total = array_sum(array_map(static fn (array $r): int => (int) $r['n'], $rows));

        if (!$apply) {
            $io->note(sprintf('Report only — re-run with --apply to convert %d row(s) to mg/dL.', $total));

            return Command::SUCCESS;
        }

        $updated = $this->connection->executeStatement(
            "UPDATE health_metric h SET h.value = ROUND(h.value * ?, 2)
             WHERE h.metric_type = 'blood_glucose' AND h.value >= ? AND h.value < ?{$userSql}",
            [UnitConverter::MGDL_PER_MMOLL, ...$params],
        );

        $io->success(sprintf('Converted %d glucose row(s) from mmol/L to mg/dL.', $updated));

        return Command::SUCCESS;
    }
}

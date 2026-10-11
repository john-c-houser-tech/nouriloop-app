<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes abandoned meal photos from public/uploads/meals: files old enough to
 * be outside any live logging session AND not referenced by a saved meal.
 *
 * Why: the log/review flow keeps in-progress uploads on disk with their only
 * reference in the session (the Confirm & Refine state, and the analysis-failure
 * retry park). A user who walks away — or a session that expires — strands the
 * file forever; a prolonged Claude outage could strand many. Saved meals are
 * untouched (their `image_path` rows are the reference set); the age threshold
 * protects photos in still-active sessions. Account purges already delete
 * row-referenced files (AccountDeletionService) — this command sweeps exactly
 * the ownerless remainder.
 *
 * Run daily from cron (see docs/ops/production.md).
 */
#[AsCommand(
    name: 'app:cleanup-meal-uploads',
    description: 'Delete abandoned meal photos not referenced by any saved meal',
)]
final class CleanupMealUploadsCommand extends Command
{
    /** Old enough that no live session (PHP session GC included) still points at it. */
    private const DEFAULT_OLDER_THAN_HOURS = 48;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $uploadsDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'older-than-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Only delete files older than this many hours',
                (string) self::DEFAULT_OLDER_THAN_HOURS,
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hours = max(1, (int) $input->getOption('older-than-hours'));
        $dryRun = (bool) $input->getOption('dry-run');
        $cutoff = time() - $hours * 3600;

        if (!is_dir($this->uploadsDir)) {
            $io->warning(sprintf('Uploads dir %s does not exist — nothing to do.', $this->uploadsDir));

            return Command::SUCCESS;
        }

        // Basenames of every photo a saved meal points at. Meals store web paths
        // ("uploads/meals/<file>"), so compare by basename.
        $referenced = array_flip(array_map(
            'basename',
            $this->connection->fetchFirstColumn(
                'SELECT image_path FROM meal_entry WHERE image_path IS NOT NULL',
            ),
        ));

        $deleted = 0;
        $kept = 0;
        $failed = 0;
        foreach (glob($this->uploadsDir . '/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $mtime = filemtime($file);
            if ($mtime === false || $mtime > $cutoff || isset($referenced[basename($file)])) {
                $kept++;
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf('Would delete %s', basename($file)));
                $deleted++;
            } elseif (@unlink($file)) {
                $deleted++;
            } else {
                // A privacy-sensitive photo we tried and FAILED to remove (e.g.
                // an ACL regression) must not be reported as deleted — surface
                // it and fail the run so cron monitoring notices.
                $io->error(sprintf('Could not delete %s', basename($file)));
                $failed++;
            }
        }

        if ($failed > 0) {
            $io->warning(sprintf(
                'Deleted %d abandoned photo(s), but %d could not be removed — check permissions/ACLs '
                . '(see the Writable var/ + uploads notes in docs/ops/production.md).',
                $deleted,
                $failed,
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s %d abandoned photo(s); %d kept (referenced or newer than %dh).',
            $dryRun ? 'Would delete' : 'Deleted',
            $deleted,
            $kept,
            $hours,
        ));

        return Command::SUCCESS;
    }
}

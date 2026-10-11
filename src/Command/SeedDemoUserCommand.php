<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DemoDataSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * (Re)seeds the demo user (demo@nouriloop.com) with ~60 days of realistic data —
 * the fixture behind the help screenshots, and a handy local review/test account.
 * Idempotent. Run against the test DB before capturing screenshots:
 *   APP_ENV=test php bin/console app:seed-demo-user
 */
#[AsCommand(name: 'app:seed-demo-user', description: 'Seed the demo user with ~60 days of realistic data')]
class SeedDemoUserCommand extends Command
{
    public function __construct(
        private readonly DemoDataSeeder $seeder,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Never in prod: the demo user has a published password (it's in the
        // docs and the seeder) and a permanent Loop+ comp, so seeding it there
        // would create a real, known credential. Dev (the local review account)
        // and test (screenshots) are the intended homes — unlike
        // app:capture-help-screenshots, dev stays allowed.
        $env = $this->kernel->getEnvironment();
        if ($env === 'prod') {
            $io->error(sprintf(
                'Refusing to seed the demo user (%s) in the prod env — it has a known password. '
                . 'Run it in dev or test.',
                DemoDataSeeder::EMAIL,
            ));

            return Command::FAILURE;
        }

        $counts = $this->seeder->seed();

        $io->success(sprintf('Seeded demo user %s.', DemoDataSeeder::EMAIL));
        foreach ($counts as $section => $n) {
            $io->writeln(sprintf('  %-14s %d', $section, $n));
        }
        $io->writeln(sprintf(
            'Log in as <info>%s</info> — the password is the <info>DemoDataSeeder::PASSWORD</info> constant.',
            DemoDataSeeder::EMAIL,
        ));

        return Command::SUCCESS;
    }
}

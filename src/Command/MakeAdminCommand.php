<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:make-admin',
    description: 'Grant (or revoke with --revoke) ROLE_ADMIN for a user by email',
)]
/**
 * Granting admin also marks the email as verified: whoever can run this on the server
 * vouches for the account, and a self-hosted copy without email (MAILER_DSN=null://null)
 * has no other way to clear the verification banner or receive emailed check-ins.
 */
class MakeAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly EmailVerifier $emailVerifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The user\'s email address')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Remove ROLE_ADMIN instead of granting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $revoke = (bool) $input->getOption('revoke');

        $user = $this->users->findOneBy(['email' => $email]);
        if ($user === null) {
            $io->error(sprintf('No user found with email "%s".', $email));

            return Command::FAILURE;
        }

        $roles = array_values(array_filter($user->getRoles(), static fn (string $r): bool => $r !== 'ROLE_USER'));
        if ($revoke) {
            $roles = array_values(array_filter($roles, static fn (string $r): bool => $r !== 'ROLE_ADMIN'));
        } elseif (!in_array('ROLE_ADMIN', $roles, true)) {
            $roles[] = 'ROLE_ADMIN';
        }

        $user->setRoles($roles);
        $this->em->flush();

        $io->success(sprintf('%s is %s an admin.', $email, $revoke ? 'no longer' : 'now'));

        if (!$revoke && !$user->isEmailVerified()) {
            $this->emailVerifier->markVerified($user);
            $io->text('Their email address is now marked as verified.');
        }

        return Command::SUCCESS;
    }
}

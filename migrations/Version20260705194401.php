<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Email ownership verification (soft-gate): adds user.email_verified_at.
 *
 * Pre-existing users are backfilled as verified (stamped with their created_at):
 * they registered before verification existed and have been receiving scheduled
 * mail all along — leaving them null would silently stop their digests and goal
 * check-ins at the new send gate. Only registrations after this ships start
 * unverified.
 */
final class Version20260705194401 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.email_verified_at; backfill existing users as verified';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD email_verified_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE `user` SET email_verified_at = created_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP email_verified_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

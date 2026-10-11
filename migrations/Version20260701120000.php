<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add self-service account deletion state (grace period) to user.
 */
final class Version20260701120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deletion_requested_at to user (self-service account deletion grace period).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD deletion_requested_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP deletion_requested_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

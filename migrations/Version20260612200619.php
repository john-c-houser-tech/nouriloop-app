<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260612200619 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.timezone (IANA id) for per-user local time.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD timezone VARCHAR(64) DEFAULT \'America/Los_Angeles\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP timezone');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

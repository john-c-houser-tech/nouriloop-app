<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718023013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'i18n Phase 1: User.locale language preference (full tag, e.g. es_MX; default en)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD locale VARCHAR(10) DEFAULT \'en\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP locale');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

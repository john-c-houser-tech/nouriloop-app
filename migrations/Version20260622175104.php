<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Beta-tester grandfathering: complimentary subscription columns on user
 * (comp_tier, comp_expires_at) and a beta_tester flag on invitation_code.
 */
final class Version20260622175104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add comp subscription columns to user and beta_tester flag to invitation_code';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD comp_tier VARCHAR(20) DEFAULT NULL, ADD comp_expires_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE invitation_code ADD beta_tester TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP comp_tier, DROP comp_expires_at');
        $this->addSql('ALTER TABLE invitation_code DROP beta_tester');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

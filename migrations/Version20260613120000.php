<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add invitation_request table — persists public "request an invitation"
 * submissions so admins keep a running total and can follow up.
 */
final class Version20260613120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add invitation_request table (public invitation requests).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invitation_request (id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(180) NOT NULL, email VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE invitation_request');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the invitation_request table — the public "request a beta code" flow was
 * removed (beta testers are now solicited through other channels; registration still
 * accepts a coupon that routes to the beta-tester comp). See Version20260613120000
 * for the original table.
 */
final class Version20260709202245 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop invitation_request table (public request-a-code flow removed).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS invitation_request');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invitation_request (id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(180) NOT NULL, email VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

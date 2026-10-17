<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261014013701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user.subscription_cancel_at: when a scheduled Stripe cancellation takes effect (App Development Plan PR 8).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD subscription_cancel_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP subscription_cancel_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

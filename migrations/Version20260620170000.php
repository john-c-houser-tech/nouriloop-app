<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop nutrition_goal.delivery_channel: goal check-ins now follow the owner's
 * User.notification_channel setting instead of a per-goal channel, so the column
 * is no longer mapped or read.
 */
final class Version20260620170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop nutrition_goal.delivery_channel (goal check-ins follow the user notification channel)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nutrition_goal DROP delivery_channel');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE nutrition_goal ADD delivery_channel VARCHAR(5) DEFAULT 'email' NOT NULL");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260628153836 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add first-login onboarding state columns to user (completed/dismissed + per-step timestamps).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD onboarding_completed_at DATETIME DEFAULT NULL, ADD onboarding_dismissed TINYINT DEFAULT 0 NOT NULL, ADD onboarding_meal_at DATETIME DEFAULT NULL, ADD onboarding_goal_at DATETIME DEFAULT NULL, ADD onboarding_notifications_at DATETIME DEFAULT NULL, ADD onboarding_health_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `user` DROP onboarding_completed_at, DROP onboarding_dismissed, DROP onboarding_meal_at, DROP onboarding_goal_at, DROP onboarding_notifications_at, DROP onboarding_health_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

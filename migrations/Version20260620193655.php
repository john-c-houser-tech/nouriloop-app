<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add user.glucose_ai_enabled: the per-user entitlement flag for the AI Pattern
 * Coach glucose deep-dive (admin-toggled; gated together with the global
 * GLUCOSE_AI_INSIGHTS_ENABLED env flag).
 */
final class Version20260620193655 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.glucose_ai_enabled (per-user AI Pattern Coach entitlement)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD glucose_ai_enabled TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP glucose_ai_enabled');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

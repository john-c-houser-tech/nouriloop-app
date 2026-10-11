<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop user.glucose_ai_enabled — the per-user AI Pattern Coach entitlement is retired; '
            . 'the coach now follows the tier (Loop+ + the GLUCOSE_AI_INSIGHTS_ENABLED kill switch), '
            . 'and admins manage tiers via the new Upgrade/Downgrade comp actions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP glucose_ai_enabled');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD glucose_ai_enabled TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

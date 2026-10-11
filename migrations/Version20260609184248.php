<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260609184248 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `user` CHANGE notify_stale_metrics notify_stale_metrics TINYINT DEFAULT 0 NOT NULL, CHANGE notify_calorie_range notify_calorie_range TINYINT DEFAULT 0 NOT NULL, CHANGE notify_supplements notify_supplements TINYINT DEFAULT 0 NOT NULL, CHANGE notify_no_meals notify_no_meals TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `user` CHANGE notify_no_meals notify_no_meals TINYINT DEFAULT 1 NOT NULL, CHANGE notify_stale_metrics notify_stale_metrics TINYINT DEFAULT 1 NOT NULL, CHANGE notify_calorie_range notify_calorie_range TINYINT DEFAULT 1 NOT NULL, CHANGE notify_supplements notify_supplements TINYINT DEFAULT 1 NOT NULL');
    }
}

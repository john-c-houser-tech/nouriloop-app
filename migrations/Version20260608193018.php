<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260608193018 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE notification_log (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(32) NOT NULL, sent_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_ED15DF2A76ED395 (user_id), INDEX idx_user_type_sent (user_id, type, sent_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notification_log ADD CONSTRAINT FK_ED15DF2A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE `user` ADD notify_stale_metrics TINYINT DEFAULT 1 NOT NULL, ADD notify_calorie_range TINYINT DEFAULT 1 NOT NULL, ADD notify_supplements TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification_log DROP FOREIGN KEY FK_ED15DF2A76ED395');
        $this->addSql('DROP TABLE notification_log');
        $this->addSql('ALTER TABLE `user` DROP notify_stale_metrics, DROP notify_calorie_range, DROP notify_supplements');
    }
}

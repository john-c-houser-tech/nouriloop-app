<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260611015440 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add favorite table and meal_entry.favorite_id (ON DELETE SET NULL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE favorite (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(200) NOT NULL, thumbnail_path VARCHAR(500) DEFAULT NULL, ai_description LONGTEXT DEFAULT NULL, nutrients JSON NOT NULL, ingredients JSON NOT NULL, tokens_per_analysis INT DEFAULT 0 NOT NULL, use_count INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_68C58ED9A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE favorite ADD CONSTRAINT FK_68C58ED9A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE meal_entry ADD favorite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_entry ADD CONSTRAINT FK_91EB75A6AA17481D FOREIGN KEY (favorite_id) REFERENCES favorite (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_91EB75A6AA17481D ON meal_entry (favorite_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meal_entry DROP FOREIGN KEY FK_91EB75A6AA17481D');
        $this->addSql('DROP INDEX IDX_91EB75A6AA17481D ON meal_entry');
        $this->addSql('ALTER TABLE meal_entry DROP favorite_id');
        $this->addSql('ALTER TABLE favorite DROP FOREIGN KEY FK_68C58ED9A76ED395');
        $this->addSql('DROP TABLE favorite');
    }
}

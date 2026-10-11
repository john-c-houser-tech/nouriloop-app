<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260611234602 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nutrition_goal and goal_checkin tables (scheduled goal check-ins)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE goal_checkin (id INT AUTO_INCREMENT NOT NULL, run_at DATETIME NOT NULL, window_start DATETIME NOT NULL, window_end DATETIME NOT NULL, status VARCHAR(10) NOT NULL, current_value DOUBLE PRECISION DEFAULT NULL, target_value DOUBLE PRECISION DEFAULT NULL, summary_text LONGTEXT DEFAULT NULL, chart_path VARCHAR(500) DEFAULT NULL, prompt_version INT DEFAULT 1 NOT NULL, input_tokens INT DEFAULT 0 NOT NULL, output_tokens INT DEFAULT 0 NOT NULL, goal_id INT NOT NULL, INDEX IDX_90481E6D667D1AFE (goal_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE nutrition_goal (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, definition JSON NOT NULL, evaluation_prompt_template LONGTEXT NOT NULL, prompt_version INT DEFAULT 1 NOT NULL, frequency VARCHAR(10) NOT NULL, day_of_week SMALLINT DEFAULT NULL, hour SMALLINT DEFAULT 18 NOT NULL, minute SMALLINT DEFAULT 0 NOT NULL, output_format VARCHAR(10) DEFAULT \'summary\' NOT NULL, delivery_channel VARCHAR(5) DEFAULT \'email\' NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, next_run_at DATETIME DEFAULT NULL, last_run_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_D7E605BDA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE goal_checkin ADD CONSTRAINT FK_90481E6D667D1AFE FOREIGN KEY (goal_id) REFERENCES nutrition_goal (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE nutrition_goal ADD CONSTRAINT FK_D7E605BDA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE goal_checkin DROP FOREIGN KEY FK_90481E6D667D1AFE');
        $this->addSql('ALTER TABLE nutrition_goal DROP FOREIGN KEY FK_D7E605BDA76ED395');
        $this->addSql('DROP TABLE goal_checkin');
        $this->addSql('DROP TABLE nutrition_goal');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

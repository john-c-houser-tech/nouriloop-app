<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260608002928 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE health_metric (id INT AUTO_INCREMENT NOT NULL, metric_type VARCHAR(255) NOT NULL, value DOUBLE PRECISION NOT NULL, recorded_at DATETIME NOT NULL, source VARCHAR(100) DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_FC3CC666A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE meal_entry (id INT AUTO_INCREMENT NOT NULL, image_path VARCHAR(500) DEFAULT NULL, ai_description LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, meal_type VARCHAR(255) NOT NULL, logged_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_91EB75A6A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE nutrition_data (id INT AUTO_INCREMENT NOT NULL, calories DOUBLE PRECISION DEFAULT NULL, protein DOUBLE PRECISION DEFAULT NULL, carbohydrates DOUBLE PRECISION DEFAULT NULL, fat DOUBLE PRECISION DEFAULT NULL, saturated_fat DOUBLE PRECISION DEFAULT NULL, fiber DOUBLE PRECISION DEFAULT NULL, sugar DOUBLE PRECISION DEFAULT NULL, vitamin_a DOUBLE PRECISION DEFAULT NULL, vitamin_b1 DOUBLE PRECISION DEFAULT NULL, vitamin_b2 DOUBLE PRECISION DEFAULT NULL, vitamin_b3 DOUBLE PRECISION DEFAULT NULL, vitamin_b6 DOUBLE PRECISION DEFAULT NULL, vitamin_b9 DOUBLE PRECISION DEFAULT NULL, vitamin_b12 DOUBLE PRECISION DEFAULT NULL, vitamin_c DOUBLE PRECISION DEFAULT NULL, vitamin_d DOUBLE PRECISION DEFAULT NULL, vitamin_e DOUBLE PRECISION DEFAULT NULL, vitamin_k DOUBLE PRECISION DEFAULT NULL, calcium DOUBLE PRECISION DEFAULT NULL, iron DOUBLE PRECISION DEFAULT NULL, magnesium DOUBLE PRECISION DEFAULT NULL, phosphorus DOUBLE PRECISION DEFAULT NULL, potassium DOUBLE PRECISION DEFAULT NULL, sodium DOUBLE PRECISION DEFAULT NULL, zinc DOUBLE PRECISION DEFAULT NULL, selenium DOUBLE PRECISION DEFAULT NULL, confidence_score DOUBLE PRECISION DEFAULT NULL, meal_entry_id INT NOT NULL, UNIQUE INDEX UNIQ_86C91DF0DE245230 (meal_entry_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, display_name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE health_metric ADD CONSTRAINT FK_FC3CC666A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE meal_entry ADD CONSTRAINT FK_91EB75A6A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE nutrition_data ADD CONSTRAINT FK_86C91DF0DE245230 FOREIGN KEY (meal_entry_id) REFERENCES meal_entry (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE health_metric DROP FOREIGN KEY FK_FC3CC666A76ED395');
        $this->addSql('ALTER TABLE meal_entry DROP FOREIGN KEY FK_91EB75A6A76ED395');
        $this->addSql('ALTER TABLE nutrition_data DROP FOREIGN KEY FK_86C91DF0DE245230');
        $this->addSql('DROP TABLE health_metric');
        $this->addSql('DROP TABLE meal_entry');
        $this->addSql('DROP TABLE nutrition_data');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE messenger_messages');
    }
}

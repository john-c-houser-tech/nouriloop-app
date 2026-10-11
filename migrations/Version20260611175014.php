<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260611175014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add symptom_log table (daily joint pain/stiffness check-in)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE symptom_log (id INT AUTO_INCREMENT NOT NULL, logged_date DATE NOT NULL, pain_level SMALLINT NOT NULL, stiffness_level SMALLINT DEFAULT NULL, note LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_64508847A76ED395 (user_id), UNIQUE INDEX uq_symptom_log (user_id, logged_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE symptom_log ADD CONSTRAINT FK_64508847A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE symptom_log DROP FOREIGN KEY FK_64508847A76ED395');
        $this->addSql('DROP TABLE symptom_log');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

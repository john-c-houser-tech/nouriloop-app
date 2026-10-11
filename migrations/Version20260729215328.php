<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729215328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Meal history Phase 3 — the analysis_event call-time ledger (re-analyses leave no countable row, '
            . 'so the Free monthly allowance is counted from these) and MealEntry.reanalyzed_at (provenance: '
            . 'the nutrition numbers are a fresh AI estimate from an applied re-analysis)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE analysis_event (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(40) NOT NULL, occurred_at DATETIME NOT NULL, tokens INT NOT NULL, user_id INT NOT NULL, meal_entry_id INT DEFAULT NULL, INDEX IDX_C478212A76ED395 (user_id), INDEX IDX_C478212DE245230 (meal_entry_id), INDEX idx_user_occurred (user_id, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4'); // phpcs:ignore Generic.Files.LineLength
        $this->addSql('ALTER TABLE analysis_event ADD CONSTRAINT FK_C478212A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE analysis_event ADD CONSTRAINT FK_C478212DE245230 FOREIGN KEY (meal_entry_id) REFERENCES meal_entry (id) ON DELETE SET NULL'); // phpcs:ignore Generic.Files.LineLength
        $this->addSql('ALTER TABLE meal_entry ADD reanalyzed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analysis_event DROP FOREIGN KEY FK_C478212A76ED395');
        $this->addSql('ALTER TABLE analysis_event DROP FOREIGN KEY FK_C478212DE245230');
        $this->addSql('DROP TABLE analysis_event');
        $this->addSql('ALTER TABLE meal_entry DROP reanalyzed_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

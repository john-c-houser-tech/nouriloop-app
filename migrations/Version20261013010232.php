<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013010232 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ai_call: one row per AI call (feature, user, provider, model, tokens, estimated cost) — '
            . 'the per-call cost record from ADR 0003. user_id SET NULL so a purged account\'s calls stay as anonymous rows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_call (id INT AUTO_INCREMENT NOT NULL, feature VARCHAR(40) NOT NULL, provider VARCHAR(100) NOT NULL, model VARCHAR(150) NOT NULL, input_tokens INT NOT NULL, output_tokens INT NOT NULL, cost_micros INT DEFAULT NULL, occurred_at DATETIME NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_C8DF6E85A76ED395 (user_id), INDEX idx_ai_call_occurred (occurred_at), INDEX idx_ai_call_user_occurred (user_id, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ai_call ADD CONSTRAINT FK_C8DF6E85A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_call DROP FOREIGN KEY FK_C8DF6E85A76ED395');
        $this->addSql('DROP TABLE ai_call');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

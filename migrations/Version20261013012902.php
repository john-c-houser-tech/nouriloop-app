<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013012902 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_ai_settings: a user\'s own AI provider, encrypted key and models (ADR 0003 BYOK); '
            . 'ai_call.user_key: whether the user\'s own key paid for a call.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_ai_settings (id INT AUTO_INCREMENT NOT NULL, provider VARCHAR(20) NOT NULL, base_url VARCHAR(255) DEFAULT NULL, encrypted_key LONGTEXT NOT NULL, key_hint VARCHAR(4) NOT NULL, text_model VARCHAR(150) DEFAULT NULL, vision_model VARCHAR(150) DEFAULT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX uq_user_ai_settings_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_ai_settings ADD CONSTRAINT FK_53B8EF30A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ai_call ADD user_key TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_ai_settings DROP FOREIGN KEY FK_53B8EF30A76ED395');
        $this->addSql('DROP TABLE user_ai_settings');
        $this->addSql('ALTER TABLE ai_call DROP user_key');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

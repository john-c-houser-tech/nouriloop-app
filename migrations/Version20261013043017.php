<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013043017 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'allowance_top_up: paid one-month allowance top-ups for Loop+ (PR 7b).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE allowance_top_up (id INT AUTO_INCREMENT NOT NULL, month VARCHAR(7) NOT NULL, analyses INT NOT NULL, stripe_session_id VARCHAR(255) NOT NULL, credited_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_506FAF5EA76ED395 (user_id), INDEX idx_allowance_top_up_user_month (user_id, month), UNIQUE INDEX uq_allowance_top_up_session (stripe_session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE allowance_top_up ADD CONSTRAINT FK_506FAF5EA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE allowance_top_up DROP FOREIGN KEY FK_506FAF5EA76ED395');
        $this->addSql('DROP TABLE allowance_top_up');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260612192801 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add invitation_code table (single-use, 48h registration codes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invitation_code (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_by_id INT DEFAULT NULL, used_by_id INT DEFAULT NULL, INDEX IDX_BA14FCCCB03A8386 (created_by_id), UNIQUE INDEX UNIQ_BA14FCCC4C2B72A8 (used_by_id), UNIQUE INDEX uq_invitation_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE invitation_code ADD CONSTRAINT FK_BA14FCCCB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE invitation_code ADD CONSTRAINT FK_BA14FCCC4C2B72A8 FOREIGN KEY (used_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitation_code DROP FOREIGN KEY FK_BA14FCCCB03A8386');
        $this->addSql('ALTER TABLE invitation_code DROP FOREIGN KEY FK_BA14FCCC4C2B72A8');
        $this->addSql('DROP TABLE invitation_code');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

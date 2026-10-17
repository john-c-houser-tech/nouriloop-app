<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013021703 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_ai_settings: vision-benchmark results for the user\'s current photo model (ADR 0003, PR 5).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_ai_settings ADD benchmark_model VARCHAR(150) DEFAULT NULL, ADD benchmark_results JSON DEFAULT NULL, ADD benchmark_ran_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_ai_settings DROP benchmark_model, DROP benchmark_results, DROP benchmark_ran_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

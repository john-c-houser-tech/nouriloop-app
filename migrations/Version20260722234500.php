<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260722234500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recipe import Phase 2: Favorite.batch_ingredients — the whole-batch quantities a cook '
            . 'needs, kept verbatim from the confirm screen (the ingredients column stores the '
            . 'per-serving division for the meal pipeline)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE favorite ADD batch_ingredients JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE favorite DROP batch_ingredients');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

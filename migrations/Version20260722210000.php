<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260722210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recipe photo import: Favorite.instructions (transcribed steps) + '
            . 'Favorite.servings_at_import (batch divisor; non-null marks a recipe import)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE favorite ADD instructions LONGTEXT DEFAULT NULL, '
            . 'ADD servings_at_import DOUBLE PRECISION DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE favorite DROP instructions, DROP servings_at_import');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

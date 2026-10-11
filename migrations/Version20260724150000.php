<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Meal history Phase 2 — MealEntry.edited_at (the "edited on" trust marker, stamped on any '
            . 'edit save) and MealEntry.nutrition_edited_at (provenance: nutrition numbers were manually '
            . 'corrected/rescaled by the user, so they are no longer the AI estimate)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meal_entry ADD edited_at DATETIME DEFAULT NULL, '
            . 'ADD nutrition_edited_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meal_entry DROP edited_at, DROP nutrition_edited_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

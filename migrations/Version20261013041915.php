<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013041915 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'meal_entry.from_favorite: favorite re-logs make no AI call and stop counting against the allowance (PR 7 review).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meal_entry ADD from_favorite TINYINT DEFAULT 0 NOT NULL');
        // Meals whose favorite still exists; ones from a since-deleted favorite can't be told apart.
        $this->addSql('UPDATE meal_entry SET from_favorite = 1 WHERE favorite_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meal_entry DROP from_favorite');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

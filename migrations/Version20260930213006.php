<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930213006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'meal_entry (user_id, logged_at) composite index — every hot path filters a user\'s meals '
            . 'by a loggedAt range, and the FK\'s user_id-only index left the date filter to a row scan';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_meal_entry_user_logged ON meal_entry (user_id, logged_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_meal_entry_user_logged ON meal_entry');
    }
}

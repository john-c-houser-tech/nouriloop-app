<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260723150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'GoalCheckin.trend — the evaluator\'s trend key (first/improving/holding/slipping), '
            . 'so the email and goal page can explain why an under-target check-in is "On track" '
            . '(below target but trending up, or a first check-in with nothing to compare)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE goal_checkin ADD trend VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE goal_checkin DROP trend');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

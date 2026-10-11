<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add user.unit_system (metric/imperial) for per-user weight & glucose display.
 * Backfills existing US-timezone users to imperial so they see lb + mg/dL; all
 * others stay metric (the column default). Mirrors UnitSystem::US_TIMEZONES.
 */
final class Version20260615182821 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.unit_system (metric/imperial); backfill US timezones to imperial.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD unit_system VARCHAR(10) DEFAULT \'metric\' NOT NULL');

        // Backfill every US-timezone user to imperial (matches
        // UnitSystem::defaultForTimezone). Pulled from the tz database so all
        // US sub-zones (Indiana/North Dakota/Alaska/…) are covered.
        $usZones = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, 'US');
        $inList  = implode(',', array_map(fn (string $z): string => $this->connection->quote($z), $usZones));
        $this->addSql("UPDATE `user` SET unit_system = 'imperial' WHERE timezone IN ({$inList})");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP unit_system');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

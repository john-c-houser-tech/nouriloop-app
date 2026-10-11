<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sync two pre-existing cosmetic schema drifts with the entity mapping:
 *  - supplement.servings_per_day: normalise the DOUBLE default-value representation
 *    (entity maps options.default = 1).
 *  - user.last_login_at: drop the legacy "(DC2Type:datetime_immutable)" column
 *    comment that newer Doctrine no longer emits for immutable datetimes.
 *
 * Both ALTERs re-apply a column definition, so they are idempotent-safe: running
 * up() against a DB that is already in sync (e.g. prod) is a harmless no-op.
 */
final class Version20260622180657 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sync cosmetic schema drift (supplement.servings_per_day default, user.last_login_at DC2Type comment)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplement CHANGE servings_per_day servings_per_day DOUBLE PRECISION DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE `user` CHANGE last_login_at last_login_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplement CHANGE servings_per_day servings_per_day DOUBLE PRECISION DEFAULT \'1\' NOT NULL');
        $this->addSql('ALTER TABLE `user` CHANGE last_login_at last_login_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

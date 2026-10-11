<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add first-touch marketing attribution columns to user (utm_source, utm_medium, '
            . 'utm_campaign, utm_content, gclid) — the first-party ground truth behind the '
            . 'registrations-by-campaign counts endpoint. Existing rows stay NULL (unattributed).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE `user` '
            . 'ADD utm_source VARCHAR(255) DEFAULT NULL, '
            . 'ADD utm_medium VARCHAR(255) DEFAULT NULL, '
            . 'ADD utm_campaign VARCHAR(255) DEFAULT NULL, '
            . 'ADD utm_content VARCHAR(255) DEFAULT NULL, '
            . 'ADD gclid VARCHAR(255) DEFAULT NULL'
        );
        // The counts endpoint groups by campaign over a created_at range; without
        // this the query is a full scan of the user table on every Campaigner poll.
        $this->addSql('CREATE INDEX idx_user_created_campaign ON `user` (created_at, utm_campaign)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_user_created_campaign ON `user`');
        $this->addSql(
            'ALTER TABLE `user` '
            . 'DROP utm_source, DROP utm_medium, DROP utm_campaign, DROP utm_content, DROP gclid'
        );
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared schema reset for the E2E suite. Used by both the Panther base
 * (`E2ETestCase`) and in-process `WebTestCase` tests, which can't share a base
 * class, so the truncate logic lives here to avoid drifting copies.
 */
trait DbResetTrait
{
    /** Truncate every app table so each test starts from a known-empty schema. */
    protected function truncateAll(EntityManagerInterface $em): void
    {
        $conn = $em->getConnection();
        $tables = $conn->fetchFirstColumn(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ?',
            [$conn->getDatabase()]
        );

        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            if (\in_array($table, ['doctrine_migration_versions', 'messenger_messages'], true)) {
                continue;
            }
            $conn->executeStatement('TRUNCATE TABLE ' . $conn->quoteIdentifier($table));
        }
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add Stripe subscription columns to the user table (subscription store
 * foundation): customer/subscription ids, tier, status, period end, trial end.
 */
final class Version20260622172159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Stripe subscription billing columns to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE `user`
                ADD stripe_customer_id VARCHAR(255) DEFAULT NULL,
                ADD stripe_subscription_id VARCHAR(255) DEFAULT NULL,
                ADD subscription_tier VARCHAR(20) DEFAULT 'free' NOT NULL,
                ADD subscription_status VARCHAR(20) DEFAULT NULL,
                ADD subscription_period_end DATETIME DEFAULT NULL,
                ADD trial_ends_at DATETIME DEFAULT NULL
            SQL);
        // Each Stripe customer / subscription maps to exactly one user — unique so
        // webhook sync can't attach a subscription to the wrong account. Nullable,
        // and MariaDB allows many NULLs under a unique index, so Free users (no
        // Stripe ids) are unaffected.
        $this->addSql('CREATE UNIQUE INDEX uq_user_stripe_customer ON `user` (stripe_customer_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_user_stripe_subscription ON `user` (stripe_subscription_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uq_user_stripe_customer ON `user`');
        $this->addSql('DROP INDEX uq_user_stripe_subscription ON `user`');
        $this->addSql(<<<'SQL'
            ALTER TABLE `user`
                DROP stripe_customer_id,
                DROP stripe_subscription_id,
                DROP subscription_tier,
                DROP subscription_status,
                DROP subscription_period_end,
                DROP trial_ends_at
            SQL);
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

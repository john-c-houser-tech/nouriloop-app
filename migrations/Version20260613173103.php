<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Generalize symptoms: replace the single symptom_log (pain + optional
 * stiffness per day) with a normalized model — symptom_type (per-user catalog
 * of enable-able built-in + custom symptoms), symptom_day (per-day note), and
 * symptom_entry (one 0–10 level per symptom per day).
 *
 * Backfills existing data: every user gets the 6 built-in types (joint pain
 * enabled where they have history, joint stiffness where they ever logged it;
 * the rest disabled/opt-in), and each old row becomes a symptom_day + joint
 * pain/stiffness entries. Then drops symptom_log.
 */
final class Version20260613173103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generalize symptoms into symptom_type/day/entry; backfill from symptom_log; drop symptom_log.';
    }

    public function up(Schema $schema): void
    {
        // ── A. Create the 3 tables (parents first) + FKs ──────────────────
        $this->addSql('CREATE TABLE symptom_type (id INT AUTO_INCREMENT NOT NULL, type_key VARCHAR(64) NOT NULL, label VARCHAR(100) NOT NULL, is_builtin TINYINT NOT NULL, is_enabled TINYINT NOT NULL, higher_is_better TINYINT NOT NULL, position SMALLINT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_8F83DAC5A76ED395 (user_id), UNIQUE INDEX uq_symptom_type_user_key (user_id, type_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE symptom_day (id INT AUTO_INCREMENT NOT NULL, logged_date DATE NOT NULL, note LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_ECFC912A76ED395 (user_id), UNIQUE INDEX uq_symptom_day (user_id, logged_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE symptom_entry (id INT AUTO_INCREMENT NOT NULL, level SMALLINT NOT NULL, symptom_day_id INT NOT NULL, symptom_type_id INT NOT NULL, INDEX IDX_829E6EAE68000106 (symptom_day_id), INDEX IDX_829E6EAEFE436E1B (symptom_type_id), UNIQUE INDEX uq_symptom_entry (symptom_day_id, symptom_type_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE symptom_type ADD CONSTRAINT FK_8F83DAC5A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE symptom_day ADD CONSTRAINT FK_ECFC912A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE symptom_entry ADD CONSTRAINT FK_829E6EAE68000106 FOREIGN KEY (symptom_day_id) REFERENCES symptom_day (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE symptom_entry ADD CONSTRAINT FK_829E6EAEFE436E1B FOREIGN KEY (symptom_type_id) REFERENCES symptom_type (id) ON DELETE CASCADE');

        // ── B. Seed the 6 built-in types per existing user (ids auto-assigned).
        //    Joint pain/stiffness enabled where the user has matching history;
        //    bloating/headache/energy/mental_acuity disabled (opt-in).
        $this->addSql("INSERT INTO symptom_type (user_id, type_key, label, is_builtin, is_enabled, higher_is_better, position, created_at)
            SELECT u.id, 'joint_pain', 'Joint pain', 1,
                CASE WHEN EXISTS (SELECT 1 FROM symptom_log sl WHERE sl.user_id = u.id) THEN 1 ELSE 0 END,
                0, 0, NOW() FROM `user` u");
        $this->addSql("INSERT INTO symptom_type (user_id, type_key, label, is_builtin, is_enabled, higher_is_better, position, created_at)
            SELECT u.id, 'joint_stiffness', 'Joint stiffness', 1,
                CASE WHEN EXISTS (SELECT 1 FROM symptom_log sl WHERE sl.user_id = u.id AND sl.stiffness_level IS NOT NULL) THEN 1 ELSE 0 END,
                0, 1, NOW() FROM `user` u");
        $this->addSql("INSERT INTO symptom_type (user_id, type_key, label, is_builtin, is_enabled, higher_is_better, position, created_at)
            SELECT u.id, 'bloating', 'Bloating / digestive', 1, 0, 0, 2, NOW() FROM `user` u");
        $this->addSql("INSERT INTO symptom_type (user_id, type_key, label, is_builtin, is_enabled, higher_is_better, position, created_at)
            SELECT u.id, 'headache', 'Headache / migraine', 1, 0, 0, 3, NOW() FROM `user` u");
        $this->addSql("INSERT INTO symptom_type (user_id, type_key, label, is_builtin, is_enabled, higher_is_better, position, created_at)
            SELECT u.id, 'energy', 'Energy level', 1, 0, 1, 4, NOW() FROM `user` u");
        $this->addSql("INSERT INTO symptom_type (user_id, type_key, label, is_builtin, is_enabled, higher_is_better, position, created_at)
            SELECT u.id, 'mental_acuity', 'Mental acuity', 1, 0, 1, 5, NOW() FROM `user` u");

        // ── C. One symptom_day per old symptom_log row (carries note + dates).
        $this->addSql('INSERT INTO symptom_day (user_id, logged_date, note, updated_at)
            SELECT user_id, logged_date, note, updated_at FROM symptom_log');

        // ── D/E. Backfill entries by joining on natural keys (user + date,
        //    user + type_key) — ids were auto-assigned above.
        $this->addSql("INSERT INTO symptom_entry (symptom_day_id, symptom_type_id, level)
            SELECT sd.id, st.id, sl.pain_level
            FROM symptom_log sl
            JOIN symptom_day sd ON sd.user_id = sl.user_id AND sd.logged_date = sl.logged_date
            JOIN symptom_type st ON st.user_id = sl.user_id AND st.type_key = 'joint_pain'");
        $this->addSql("INSERT INTO symptom_entry (symptom_day_id, symptom_type_id, level)
            SELECT sd.id, st.id, sl.stiffness_level
            FROM symptom_log sl
            JOIN symptom_day sd ON sd.user_id = sl.user_id AND sd.logged_date = sl.logged_date
            JOIN symptom_type st ON st.user_id = sl.user_id AND st.type_key = 'joint_stiffness'
            WHERE sl.stiffness_level IS NOT NULL");

        // ── F. Drop the old table last (after D/E read from it).
        $this->addSql('ALTER TABLE symptom_log DROP FOREIGN KEY FK_64508847A76ED395');
        $this->addSql('DROP TABLE symptom_log');
    }

    public function down(Schema $schema): void
    {
        // Recreate symptom_log and backfill joint pain/stiffness from the new
        // model. Custom/bloating/headache/energy/acuity data is lost — down is a
        // dev-only escape hatch.
        $this->addSql('CREATE TABLE symptom_log (id INT AUTO_INCREMENT NOT NULL, logged_date DATE NOT NULL, pain_level SMALLINT NOT NULL, stiffness_level SMALLINT DEFAULT NULL, note LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_64508847A76ED395 (user_id), UNIQUE INDEX uq_symptom_log (user_id, logged_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE symptom_log ADD CONSTRAINT FK_64508847A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql("INSERT INTO symptom_log (user_id, logged_date, pain_level, stiffness_level, note, updated_at)
            SELECT sd.user_id, sd.logged_date,
                COALESCE((SELECT e.level FROM symptom_entry e JOIN symptom_type t ON t.id = e.symptom_type_id
                          WHERE e.symptom_day_id = sd.id AND t.type_key = 'joint_pain'), 0),
                (SELECT e.level FROM symptom_entry e JOIN symptom_type t ON t.id = e.symptom_type_id
                          WHERE e.symptom_day_id = sd.id AND t.type_key = 'joint_stiffness'),
                sd.note, sd.updated_at
            FROM symptom_day sd");

        $this->addSql('ALTER TABLE symptom_entry DROP FOREIGN KEY FK_829E6EAE68000106');
        $this->addSql('ALTER TABLE symptom_entry DROP FOREIGN KEY FK_829E6EAEFE436E1B');
        $this->addSql('ALTER TABLE symptom_day DROP FOREIGN KEY FK_ECFC912A76ED395');
        $this->addSql('ALTER TABLE symptom_type DROP FOREIGN KEY FK_8F83DAC5A76ED395');
        $this->addSql('DROP TABLE symptom_entry');
        $this->addSql('DROP TABLE symptom_day');
        $this->addSql('DROP TABLE symptom_type');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

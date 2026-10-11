<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single place every account-deletion path goes through: the self-service
 * grace-period request/cancel, the admin's immediate delete, the daily purge cron,
 * and "delete my data, keep my account." Consolidates what used to be a private
 * method on AdminUserController so admin and self-service deletes can't drift.
 */
class AccountDeletionService
{
    /** Days between a self-service deletion request and the hard purge. */
    public const GRACE_DAYS = 30;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly SubscriptionService $subscriptions,
        private readonly string $publicDir,
    ) {
    }

    /**
     * Start the grace period: the account stays fully functional (this deliberately
     * does not touch `isActive` — see CLAUDE.md's Account deletion section for why),
     * flagged pending. Cancels any active Stripe subscription immediately so the
     * user isn't billed through the countdown.
     */
    public function requestDeletion(User $user, \DateTimeImmutable $now): void
    {
        $user->setDeletionRequestedAt($now);
        $this->subscriptions->cancelSubscriptionForDeletion($user);
        $this->em->flush();
    }

    /** Aborts a pending deletion. Does not touch Stripe — the user resubscribes if they want to. */
    public function cancelDeletion(User $user): void
    {
        $user->setDeletionRequestedAt(null);
        $this->em->flush();
    }

    /** The date the account will be hard-deleted, or null if no deletion is pending. */
    public function scheduledPurgeDate(User $user): ?\DateTimeImmutable
    {
        return $user->getDeletionRequestedAt()?->modify(sprintf('+%d days', self::GRACE_DAYS));
    }

    /**
     * Full account wipe: every row scoped to the user, then the user row itself, all
     * in one transaction; uploaded files are unlinked only after it commits, so a
     * rolled-back purge leaves a live account with its photos intact. Used by both
     * the admin's immediate delete and the grace-period purge cron.
     */
    public function purgeUser(User $user): void
    {
        $this->subscriptions->cancelSubscriptionForDeletion($user);

        $userId = $user->getId();
        \assert($userId !== null);

        $files = $this->userFilePaths($userId);
        $this->connection->transactional(function (Connection $conn) use ($userId): void {
            $this->deleteUserRows($conn, $userId);
            // Tokens go only here, not in deleteUserRows: "delete my data, keep my
            // account" keeps them. api_token.user_id has no ON DELETE, so a
            // leftover token blocks the `user` delete and rolls back the purge.
            $conn->executeStatement('DELETE FROM api_token WHERE user_id = ?', [$userId]);
            $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);
        });
        $this->unlinkFiles($files);
    }

    /**
     * "Delete my data, keep my account" — the same content wipe as purgeUser, minus
     * the user row itself: login, email/password, timezone/units/tone/notification
     * preferences, API tokens, and subscription/comp state are untouched. Also
     * resets onboarding so the overlay walks them through a genuine fresh start.
     * Does not touch Stripe — they're keeping the account.
     */
    public function purgeUserData(User $user): void
    {
        $userId = $user->getId();
        \assert($userId !== null);

        $files = $this->userFilePaths($userId);
        $this->connection->transactional(function (Connection $conn) use ($userId): void {
            $this->deleteUserRows($conn, $userId);
        });
        $this->unlinkFiles($files);

        $user->resetOnboarding();
        $this->em->flush();
    }

    /**
     * Meal photos, favorite/supplement images, and goal charts on disk. Shared by
     * purgeUser and purgeUserData since both wipe the same content; collected
     * before the row delete (which loses the paths) and unlinked after it commits.
     *
     * @return list<string>
     */
    private function userFilePaths(int $userId): array
    {
        return array_merge(
            $this->connection->fetchFirstColumn(
                'SELECT image_path FROM meal_entry WHERE user_id = ? AND image_path IS NOT NULL',
                [$userId],
            ),
            $this->connection->fetchFirstColumn(
                'SELECT thumbnail_path FROM favorite WHERE user_id = ? AND thumbnail_path IS NOT NULL',
                [$userId],
            ),
            $this->connection->fetchFirstColumn(
                'SELECT image_path FROM supplement WHERE user_id = ? AND image_path IS NOT NULL',
                [$userId],
            ),
            $this->connection->fetchFirstColumn(
                'SELECT chart_path FROM goal_checkin
                 WHERE goal_id IN (SELECT id FROM nutrition_goal WHERE user_id = ?) AND chart_path IS NOT NULL',
                [$userId],
            ),
        );
    }

    /** @param list<string> $paths public-relative paths from userFilePaths() */
    private function unlinkFiles(array $paths): void
    {
        $publicDir = rtrim($this->publicDir, '/') . '/';
        foreach ($paths as $path) {
            $file = $publicDir . ltrim((string) $path, '/');
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Deletes every row scoped to the user, in FK-safe order. Does not touch the
     * user row itself — callers decide whether to also delete it.
     */
    private function deleteUserRows(Connection $conn, int $userId): void
    {
        $conn->executeStatement(
            'DELETE FROM nutrition_data WHERE meal_entry_id IN (SELECT id FROM meal_entry WHERE user_id = ?)',
            [$userId],
        );
        // goal_checkin before nutrition_goal: goal_checkin.goal_id references it
        // (ON DELETE CASCADE, but removed explicitly here to mirror the others).
        $conn->executeStatement(
            'DELETE FROM goal_checkin WHERE goal_id IN (SELECT id FROM nutrition_goal WHERE user_id = ?)',
            [$userId],
        );
        // symptom_entry before symptom_day/symptom_type: it references both
        // (ON DELETE CASCADE, but removed explicitly here to mirror the rest).
        $conn->executeStatement(
            'DELETE FROM symptom_entry WHERE symptom_day_id IN (SELECT id FROM symptom_day WHERE user_id = ?)',
            [$userId],
        );
        // analysis_event before meal_entry: its meal_entry_id is ON DELETE SET
        // NULL, so the rows would survive as user-scoped orphans and then block
        // the `user` delete on their own non-nullable user_id.
        // meal_entry before favorite: meal_entry.favorite_id is ON DELETE SET
        // NULL, but both belong to this user and are removed here anyway.
        $childTables = [
            'analysis_event', 'meal_entry', 'favorite', 'health_metric',
            'notification_log', 'notification', 'reset_password_request', 'supplement',
            'symptom_day', 'symptom_type', 'nutrition_goal',
        ];
        foreach ($childTables as $table) {
            $conn->executeStatement("DELETE FROM {$table} WHERE user_id = ?", [$userId]);
        }
    }
}

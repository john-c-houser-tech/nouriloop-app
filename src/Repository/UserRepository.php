<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\AdminUserFilters;
use App\Entity\MealEntry;
use App\Entity\User;
use App\Enum\SubscriptionTier;
use App\Service\SubscriptionService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * The users the scheduled digest runs for: enabled accounts without a pending
     * self-service deletion. Someone who asked to leave doesn't keep getting
     * messages during the grace-period countdown, and an account an admin disabled
     * (User::isActive() false; it can't even sign in to switch them off) gets none
     * at all. Goal check-ins apply the same two conditions in
     * NutritionGoalRepository::findDue().
     *
     * @return User[]
     */
    public function findReceivingScheduledMessages(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.deletionRequestedAt IS NULL')
            ->andWhere('u.isActive = true')
            ->getQuery()
            ->getResult();
    }

    /**
     * Users whose self-service deletion grace period has elapsed as of $cutoff —
     * candidates for App\Command\PurgeDeletedAccountsCommand.
     *
     * @return User[]
     */
    public function findPendingDeletionBefore(\DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.deletionRequestedAt IS NOT NULL')
            ->andWhere('u.deletionRequestedAt <= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('u.deletionRequestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Registrations in [$from, $to) grouped by first-touch campaign, biggest first.
     * Rows with no captured campaign are one bucket with a null key — deliberately
     * returned rather than dropped, so a consumer can see how much of the period is
     * unattributed instead of mistaking the attributed slice for the whole.
     *
     * Counts only, by design: this is the aggregate behind Api\MetricsApiController
     * and must never grow a per-user shape (CLAUDE.md ▸ Registration attribution).
     * Boundaries are compared against the server-frame `created_at` (see CLAUDE.md ▸
     * Timezones — scheduling/audit instants live in the server frame). Purged
     * accounts are gone, so a historical count can fall over time.
     *
     * @return list<array{utm_campaign: string|null, count: int}>
     */
    public function countRegistrationsByCampaign(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{campaign: string|null, cnt: int|string}> $rows */
        $rows = $this->createQueryBuilder('u')
            ->select('u.utmCampaign AS campaign', 'COUNT(u.id) AS cnt')
            ->andWhere('u.createdAt >= :from')
            ->andWhere('u.createdAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('u.utmCampaign')
            ->orderBy('cnt', 'DESC')
            // Stable tie-break so repeated polls (and tests) get the same order.
            ->addOrderBy('u.utmCampaign', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'utm_campaign' => $row['campaign'],
                'count' => (int) $row['cnt'],
            ],
            $rows,
        );
    }

    /**
     * One page of users for the admin list, newest first, narrowed by $filters (free
     * text + effective tier + activity + status). Flat entity list with no fetch
     * joins, so plain setFirstResult/setMaxResults is enough — no need for Doctrine's
     * Paginator (that mainly earns its keep when a join could otherwise duplicate
     * rows). Pair with countSearch() for the total.
     *
     * `id` is a secondary sort key: `createdAt` is a second-precision DATETIME, so
     * ties (bulk imports, near-simultaneous signups) would otherwise make the order
     * unstable across pages — LIMIT/OFFSET could silently skip or repeat a user at a
     * page boundary. `id` is unique and monotonic with creation order, so it's a
     * free, deterministic tiebreaker.
     *
     * @return User[]
     */
    public function searchPage(AdminUserFilters $filters, int $page, int $perPage): array
    {
        $qb = $this->searchQueryBuilder($filters)
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return $qb->getQuery()->getResult();
    }

    /** Total matching users for the same filters searchPage() applies. */
    public function countSearch(AdminUserFilters $filters): int
    {
        return (int) $this->searchQueryBuilder($filters)
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function searchQueryBuilder(AdminUserFilters $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('u');

        $q = trim((string) $filters->q);
        if ($q !== '') {
            // The search text is literal: escape LIKE's wildcards (and the escape
            // character itself — MariaDB's default is the backslash), so "a_b"
            // doesn't also match "axb" and a bare "%" doesn't match everyone.
            $qb->andWhere('u.email LIKE :q OR u.displayName LIKE :q')
                ->setParameter('q', '%' . addcslashes($q, '\\%_') . '%');
        }

        if ($filters->tier !== null) {
            $this->applyTierFilter($qb, $filters->tier, $filters->now);
        }

        $this->applyActivityFilter($qb, $filters->activity, $filters->activeCutoff);
        $this->applyStatusFilter($qb, $filters->status);

        return $qb;
    }

    /**
     * Narrow to users whose *effective* tier equals $tier — the same resolution
     * SubscriptionService::effectiveTier() does (highest of an active comp and an
     * active/in-grace subscription), expressed in DQL so it composes with pagination.
     * Kept here as the single place that mirrors the service's rules; the three tier
     * cases are mutually exclusive and exhaustive.
     */
    private function applyTierFilter(QueryBuilder $qb, SubscriptionTier $tier, \DateTimeImmutable $now): void
    {
        // An active complimentary grant of a specific tier. The `IS NOT NULL` guards
        // force a strict true/false: without them a NULL compTier/subscriptionStatus
        // makes the comparison NULL (SQL three-valued logic), which would then make the
        // Free case's NOT(...) evaluate to NULL and wrongly drop the row.
        $compActive = static fn (string $param): string =>
            "(u.compTier IS NOT NULL AND u.compTier = :$param"
            . ' AND (u.compExpiresAt IS NULL OR u.compExpiresAt > :now))';
        // An active (or in-grace) Stripe subscription of a specific tier.
        $subActive = static fn (string $param): string =>
            "(u.subscriptionTier = :$param AND u.subscriptionStatus IS NOT NULL"
            . ' AND (u.subscriptionStatus IN (:activeStatuses)'
            . ' OR (u.subscriptionStatus IN (:graceStatuses)'
            . ' AND u.subscriptionPeriodEnd IS NOT NULL AND u.subscriptionPeriodEnd > :now)))';

        $loop = $compActive('tierLoop') . ' OR ' . $subActive('tierLoop');
        $plus = $compActive('tierPlus') . ' OR ' . $subActive('tierPlus');

        $qb->setParameter('now', $now)
            ->setParameter('activeStatuses', SubscriptionService::ACTIVE_STATUSES)
            ->setParameter('graceStatuses', SubscriptionService::GRACE_STATUSES);

        switch ($tier) {
            case SubscriptionTier::LoopPlus:
                $qb->andWhere($plus)
                    ->setParameter('tierPlus', SubscriptionTier::LoopPlus->value);
                break;
            case SubscriptionTier::Loop:
                // Loop somewhere active, but not elevated to Loop+ anywhere.
                $qb->andWhere("($loop) AND NOT ($plus)")
                    ->setParameter('tierLoop', SubscriptionTier::Loop->value)
                    ->setParameter('tierPlus', SubscriptionTier::LoopPlus->value);
                break;
            case SubscriptionTier::Free:
                // Effective Free = nothing active above Free (neither Loop nor Loop+).
                $qb->andWhere("NOT (($loop) OR ($plus))")
                    ->setParameter('tierLoop', SubscriptionTier::Loop->value)
                    ->setParameter('tierPlus', SubscriptionTier::LoopPlus->value);
                break;
        }
    }

    /**
     * "Active" mirrors the admin list's badge: logged in AND logged a meal, both
     * within the trailing window ($cutoff). Inactive is the exact negation so the two
     * partition every user.
     */
    private function applyActivityFilter(QueryBuilder $qb, ?string $activity, \DateTimeImmutable $cutoff): void
    {
        if ($activity === null) {
            return;
        }

        $mealSince = 'SELECT IDENTITY(m.user) FROM ' . MealEntry::class . ' m WHERE m.loggedAt >= :cutoff';

        if ($activity === AdminUserFilters::ACTIVITY_ACTIVE) {
            $qb->andWhere("u.lastLoginAt >= :cutoff AND u.id IN ($mealSince)");
        } else {
            $qb->andWhere(
                "u.lastLoginAt IS NULL OR u.lastLoginAt < :cutoff OR u.id NOT IN ($mealSince)"
            );
        }

        $qb->setParameter('cutoff', $cutoff);
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        switch ($status) {
            case AdminUserFilters::STATUS_ENABLED:
                $qb->andWhere('u.isActive = true');
                break;
            case AdminUserFilters::STATUS_DISABLED:
                $qb->andWhere('u.isActive = false');
                break;
            case AdminUserFilters::STATUS_PENDING_DELETION:
                $qb->andWhere('u.deletionRequestedAt IS NOT NULL');
                break;
            case AdminUserFilters::STATUS_UNVERIFIED:
                $qb->andWhere('u.emailVerifiedAt IS NULL');
                break;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Enum\MealType;
use App\Service\UserTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MealEntry>
 */
class MealEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly UserTime $userTime)
    {
        parent::__construct($registry, MealEntry::class);
    }

    /**
     * Number of AI-analysed meals the user has logged in their current local calendar
     * month — the count behind the monthly analysis allowance. Meals logged from a
     * favorite are a copy with no AI call, so they don't count (MealEntry::$fromFavorite). The month boundary is
     * built in the user's timezone (their stored loggedAt is wall-clock), matching
     * how "today"/ranges are bucketed elsewhere.
     */
    public function countForUserThisMonth(User $user): int
    {
        $now        = $this->userTime->now($user);
        $monthStart = $now->setDate((int) $now->format('Y'), (int) $now->format('n'), 1)->setTime(0, 0);
        $nextMonth  = $monthStart->modify('+1 month');

        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.user = :user')
            ->andWhere('m.loggedAt >= :start')
            ->andWhere('m.loggedAt < :end')
            ->andWhere('m.fromFavorite = false')
            ->setParameter('user', $user)
            ->setParameter('start', $monthStart)
            ->setParameter('end', $nextMonth)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * IDs of users who have logged at least one meal since $since. Single query
     * to power the admin user list's "active user" indicator without N+1.
     *
     * @return int[]
     */
    public function userIdsWithMealSince(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.user) AS userId')
            ->andWhere('m.loggedAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('m.user')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['userId'], $rows);
    }

    /**
     * Meals in the half-open range [from, to). Every day-range query in the meal,
     * nutrition, health-metric, and symptom repositories takes this shape: callers
     * pass `to` = the start of the day after the last one they want (UserTime::
     * startOfTomorrow, the next window's start), so a meal logged at exactly
     * 00:00 belongs to one day, not two. Until 2026-09-30 these compared
     * `<= :to`, which double-counted such meals.
     *
     * @return MealEntry[]
     */
    public function findByUserAndDateRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->andWhere('m.loggedAt >= :from')
            ->andWhere('m.loggedAt < :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('m.loggedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Meals in a range with their nutrition eagerly fetched (avoids N+1 when
     * scoring every meal, e.g. the inflammation score).
     *
     * @return MealEntry[]
     */
    public function findWithNutritionForRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.nutritionData', 'n')
            ->addSelect('n')
            ->andWhere('m.user = :user')
            ->andWhere('m.loggedAt >= :from')
            ->andWhere('m.loggedAt < :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('m.loggedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * One page of the user's meal history, newest first, nutrition fetch-joined
     * (each row shows calories/macros, so a lazy load would be an N+1; the join
     * is to-one, so setMaxResults paginates safely). Filters are optional; the
     * boundaries compare directly against the stored wall-clock loggedAt, so no
     * timezone conversion is involved (see the Timezones storage model).
     *
     * @return MealEntry[]
     */
    public function historyPage(
        User $user,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        ?MealType $type,
        int $page,
        int $perPage,
    ): array {
        return $this->historyQueryBuilder($user, $from, $to, $type)
            ->leftJoin('m.nutritionData', 'n')
            ->addSelect('n')
            ->orderBy('m.loggedAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /** Total meals matching the same filters historyPage() applies. */
    public function countHistory(User $user, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?MealType $type): int
    {
        return (int) $this->historyQueryBuilder($user, $from, $to, $type)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function historyQueryBuilder(
        User $user,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        ?MealType $type,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user);

        if ($from !== null) {
            $qb->andWhere('m.loggedAt >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('m.loggedAt < :to')->setParameter('to', $to);
        }
        if ($type !== null) {
            $qb->andWhere('m.mealType = :type')->setParameter('type', $type);
        }

        return $qb;
    }

    /**
     * Returns the latest dinner time and total dinner calories per day.
     *
     * @return list<array{date: string, dinner_time: string, calories: float|null}>
     */
    public function getDinnerSummary(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT DATE(m.logged_at)    AS date,
                    MAX(m.logged_at)     AS dinner_time,
                    ROUND(SUM(n.calories),0) AS calories
             FROM meal_entry m
             LEFT JOIN nutrition_data n ON n.meal_entry_id = m.id
             WHERE m.user_id   = :user
               AND m.meal_type = 'dinner'
               AND m.logged_at >= :from
               AND m.logged_at < :to
             GROUP BY DATE(m.logged_at)
             ORDER BY date ASC",
            [
                'user' => $user->getId(),
                'from' => $from->format('Y-m-d H:i:s'),
                'to'   => $to->format('Y-m-d H:i:s'),
            ]
        );
    }
}

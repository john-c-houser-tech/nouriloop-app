<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GoalCheckin;
use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalCheckinStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GoalCheckin>
 */
class GoalCheckinRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoalCheckin::class);
    }

    /**
     * @return GoalCheckin[] most recent first
     */
    public function findRecentForGoal(NutritionGoal $goal, int $limit = 20): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.goal = :goal')
            ->setParameter('goal', $goal)
            ->orderBy('c.runAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * How many *evaluated* check-ins (Achieved / On-track / Off-track) the user has
     * accumulated across all their goals — the derived count behind the Free tier's
     * lifetime allowance (see CheckinAllowance). No-data and error runs make no
     * Claude narrative call, so they deliberately don't count.
     */
    public function countEvaluatedForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->innerJoin('c.goal', 'g')
            ->andWhere('g.user = :user')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [
                GoalCheckinStatus::Achieved,
                GoalCheckinStatus::OnTrack,
                GoalCheckinStatus::OffTrack,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The most recent prior check-in before $before, for trend comparison.
     */
    public function findPrevious(NutritionGoal $goal, \DateTimeImmutable $before): ?GoalCheckin
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.goal = :goal')
            ->andWhere('c.runAt < :before')
            ->setParameter('goal', $goal)
            ->setParameter('before', $before)
            ->orderBy('c.runAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

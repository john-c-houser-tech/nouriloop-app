<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NutritionGoal;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NutritionGoal>
 */
class NutritionGoalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NutritionGoal::class);
    }

    /**
     * @return NutritionGoal[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.user = :user')
            ->setParameter('user', $user)
            ->orderBy('g.isActive', 'DESC')
            ->addOrderBy('g.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active goals whose next scheduled run is due at or before $now. Used by the
     * cron-driven runner; due state lives in the DB so it survives restarts. Excludes
     * goals whose owner has a pending self-service account deletion or a disabled
     * account (the digest's UserRepository::findReceivingScheduledMessages() rule):
     * no check-ins during the grace period, and none for an account an admin cut
     * off. A re-enabled account's overdue goals run at the next hourly pass.
     *
     * @return NutritionGoal[]
     */
    public function findDue(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('g')
            ->innerJoin('g.user', 'u')
            ->andWhere('g.isActive = true')
            ->andWhere('g.nextRunAt IS NOT NULL')
            ->andWhere('g.nextRunAt <= :now')
            ->andWhere('u.deletionRequestedAt IS NULL')
            ->andWhere('u.isActive = true')
            ->setParameter('now', $now)
            ->orderBy('g.nextRunAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

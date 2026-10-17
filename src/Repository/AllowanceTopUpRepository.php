<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AllowanceTopUp;
use App\Entity\User;
use App\Service\UserTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AllowanceTopUp>
 */
class AllowanceTopUpRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly UserTime $userTime)
    {
        parent::__construct($registry, AllowanceTopUp::class);
    }

    /** Extra analyses the user bought for their current local calendar month. */
    public function analysesForUserThisMonth(User $user): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.analyses), 0)')
            ->andWhere('t.user = :user')
            ->andWhere('t.month = :month')
            ->setParameter('user', $user)
            ->setParameter('month', $this->userTime->now($user)->format('Y-m'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function isCredited(string $stripeSessionId): bool
    {
        return $this->count(['stripeSessionId' => $stripeSessionId]) > 0;
    }
}

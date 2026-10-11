<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AnalysisEvent;
use App\Entity\User;
use App\Service\UserTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalysisEvent>
 */
class AnalysisEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly UserTime $userTime)
    {
        parent::__construct($registry, AnalysisEvent::class);
    }

    /**
     * Ledgered analyses in the user's current local calendar month — the
     * re-analysis half of the Free-tier allowance. The month boundary is built
     * in the user's timezone because occurredAt is stored as their wall-clock,
     * exactly like MealEntryRepository::countForUserThisMonth.
     */
    public function countForUserThisMonth(User $user): int
    {
        $now        = $this->userTime->now($user);
        $monthStart = $now->setDate((int) $now->format('Y'), (int) $now->format('n'), 1)->setTime(0, 0);

        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.user = :user')
            ->andWhere('e.occurredAt >= :start')
            ->andWhere('e.occurredAt < :end')
            ->setParameter('user', $user)
            ->setParameter('start', $monthStart)
            ->setParameter('end', $monthStart->modify('+1 month'))
            ->getQuery()
            ->getSingleScalarResult();
    }
}

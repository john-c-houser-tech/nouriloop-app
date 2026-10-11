<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Supplement;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Supplement>
 */
class SupplementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Supplement::class);
    }

    /**
     * @return Supplement[]
     */
    public function findByUserOrdered(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.isActive', 'DESC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The user's active supplements: what DailyIntake counts on each meal day
     * from the day each was added.
     *
     * @return list<Supplement>
     */
    public function findActive(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.isActive = true')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
}

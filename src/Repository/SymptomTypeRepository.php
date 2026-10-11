<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SymptomType;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SymptomType>
 */
class SymptomTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SymptomType::class);
    }

    /**
     * Every symptom type for the user (built-in + custom), in display order.
     *
     * @return SymptomType[]
     */
    public function findByUserOrdered(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Only the enabled types (what the user actually tracks), in display order.
     *
     * @return SymptomType[]
     */
    public function findEnabledByUserOrdered(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.isEnabled = true')
            ->setParameter('user', $user)
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Existing type keys for the user, for idempotent default-seeding.
     *
     * @return string[]
     */
    public function existingKeysFor(User $user): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.typeKey')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r): string => $r['typeKey'], $rows);
    }

    /** The user's symptom type with this key, or null if not seeded yet. */
    public function findOneByUserAndKey(User $user, string $typeKey): ?SymptomType
    {
        return $this->findOneBy(['user' => $user, 'typeKey' => $typeKey]);
    }

    /** Highest position currently used by the user (−1 if none), for appending. */
    public function maxPositionFor(User $user): int
    {
        $max = $this->createQueryBuilder('t')
            ->select('MAX(t.position)')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? -1 : (int) $max;
    }
}

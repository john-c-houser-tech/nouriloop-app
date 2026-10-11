<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SymptomDay;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SymptomDay>
 */
class SymptomDayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SymptomDay::class);
    }

    public function findOneByUserAndDate(User $user, \DateTimeImmutable $date): ?SymptomDay
    {
        return $this->findOneBy(['user' => $user, 'loggedDate' => $date->setTime(0, 0)]);
    }

    /**
     * Recent days, newest first, with their entries + types eager-loaded for the
     * history table.
     *
     * @return SymptomDay[]
     */
    public function findRecent(User $user, int $limit = 60): array
    {
        // Two-step: take the most recent day ids WITHOUT the to-many entries join
        // (so LIMIT counts days, not joined rows), then hydrate those days with
        // their entries. A fetch-join + setMaxResults would truncate joined rows.
        $ids = $this->createQueryBuilder('d')
            ->select('d.id')
            ->andWhere('d.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.loggedDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->leftJoin('d.entries', 'e')->addSelect('e')
            ->leftJoin('e.symptomType', 't')->addSelect('t')
            ->andWhere('d.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('d.loggedDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Symptom levels for the range, grouped by symptom_type id then date — the
     * correlation feed. One query joining entries → day.
     *
     * @return array<int, array<string, int>> [typeId => ['Y-m-d' => level]]
     */
    public function getLevelsByTypeForRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(e.symptomType) AS typeId', 'd.loggedDate AS loggedDate', 'e.level AS level')
            ->innerJoin('d.entries', 'e')
            ->andWhere('d.user = :user')
            ->andWhere('d.loggedDate >= :from')
            ->andWhere('d.loggedDate < :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('d.loggedDate', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            /** @var \DateTimeImmutable $date */
            $date = $row['loggedDate'];
            $out[(int) $row['typeId']][$date->format('Y-m-d')] = (int) $row['level'];
        }

        return $out;
    }
}

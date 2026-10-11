<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InvitationCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvitationCode>
 */
class InvitationCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvitationCode::class);
    }

    /**
     * The redeemable lookup: a matching code that is unused and not yet expired.
     */
    public function findValid(string $code, \DateTimeImmutable $now): ?InvitationCode
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.code = :code')
            ->andWhere('c.usedBy IS NULL')
            ->andWhere('c.expiresAt > :now')
            ->setParameter('code', $code)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Unused codes (active + lapsed-but-unused), newest first, for the admin
     * panel. Redeemed codes are surfaced on their user's row instead.
     *
     * @return InvitationCode[]
     */
    public function findOutstanding(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.usedBy IS NULL')
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

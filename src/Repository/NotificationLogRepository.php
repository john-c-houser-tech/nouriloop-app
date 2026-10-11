<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotificationLog;
use App\Entity\User;
use App\Enum\NotificationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationLog>
 */
class NotificationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationLog::class);
    }

    /**
     * Whether a notification of this type has been sent to the user at or after $since.
     */
    public function hasSentSince(User $user, NotificationType $type, \DateTimeImmutable $since): bool
    {
        $count = (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.user = :user')
            ->andWhere('n.type = :type')
            ->andWhere('n.sentAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('type', $type->value)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}

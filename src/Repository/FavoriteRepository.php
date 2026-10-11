<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Favorite;
use App\Entity\User;
use App\Service\UserTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Favorite>
 */
class FavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly UserTime $userTime)
    {
        parent::__construct($registry, Favorite::class);
    }

    public const SORTS = ['recent', 'used', 'name'];

    /**
     * The user's favorites for the picker, ordered by $sort:
     *  - "recent" (default): most-recently-used first (never-used fall to the
     *    bottom), then most-used, then newest;
     *  - "used": most-used first;
     *  - "name": alphabetical.
     *
     * @return Favorite[]
     */
    public function findByUserOrdered(User $user, string $sort = 'recent'): array
    {
        $qb = $this->createQueryBuilder('f')
            ->andWhere('f.user = :user')
            ->setParameter('user', $user);

        match ($sort) {
            'used' => $qb->orderBy('f.useCount', 'DESC')->addOrderBy('f.name', 'ASC'),
            'name' => $qb->orderBy('f.name', 'ASC'),
            default => $qb->orderBy('f.lastUsedAt', 'DESC')
                ->addOrderBy('f.useCount', 'DESC')
                ->addOrderBy('f.createdAt', 'DESC'),
        };

        return $qb->getQuery()->getResult();
    }

    /**
     * Recipe imports this calendar month (user's local frame), identified by a
     * non-null servingsAtImport. Each import was one Claude vision call, so
     * they draw from the same Free-tier monthly allowance as logged meals (see
     * AnalysisAllowance). createdAt is stored in the SERVER frame, so the
     * user-local month boundaries are converted back to the server timezone
     * before comparing (Copilot catch on PR #171 — passing the user-frame
     * instants verbatim miscounts imports near a month edge).
     *
     * ⚠️ Accepted tradeoff (same posture as the meal-photo count and the
     * CheckinAllowance goal-delete reset): the marker is the saved favorite,
     * so an abandoned confirm or a deleted favorite isn't counted even though
     * the Claude call happened. Revisit with a call-time usage ledger only if
     * abuse appears.
     */
    public function countRecipeImportsForUserThisMonth(User $user): int
    {
        $now        = $this->userTime->now($user);
        $monthStart = $now->setDate((int) $now->format('Y'), (int) $now->format('n'), 1)->setTime(0, 0);
        $serverTz   = new \DateTimeZone(date_default_timezone_get());

        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.user = :user')
            ->andWhere('f.servingsAtImport IS NOT NULL')
            ->andWhere('f.createdAt >= :start')
            ->andWhere('f.createdAt < :end')
            ->setParameter('user', $user)
            ->setParameter('start', $monthStart->setTimezone($serverTz))
            ->setParameter('end', $monthStart->modify('+1 month')->setTimezone($serverTz))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Per-user reuse savings across every user who has favorites, most tokens
     * saved first — for the admin report.
     *
     * @return array<array{userId: int, email: string, displayName: string,
     *     favoriteCount: int, calls: int, tokens: int}>
     */
    public function savingsByUser(): array
    {
        /** @var array<array{userId: int, email: string, displayName: string, favoriteCount: int|string, calls: int|string, tokens: int|string}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select(
                'u.id AS userId',
                'u.email AS email',
                'u.displayName AS displayName',
                'COUNT(f.id) AS favoriteCount',
                'COALESCE(SUM(f.useCount), 0) AS calls',
                'COALESCE(SUM(f.useCount * f.tokensPerAnalysis), 0) AS tokens',
            )
            ->join('f.user', 'u')
            ->groupBy('u.id')
            ->orderBy('tokens', 'DESC')
            ->addOrderBy('calls', 'DESC')
            ->getQuery()
            ->getArrayResult();

        // DQL aggregates come back as numeric strings; normalize to ints.
        return array_map(static fn (array $r): array => [
            'userId'        => (int) $r['userId'],
            'email'         => (string) $r['email'],
            'displayName'   => (string) $r['displayName'],
            'favoriteCount' => (int) $r['favoriteCount'],
            'calls'         => (int) $r['calls'],
            'tokens'        => (int) $r['tokens'],
        ], $rows);
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NutritionData;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NutritionData>
 */
class NutritionDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NutritionData::class);
    }

    /**
     * Per local day in the half-open [from, to), every nutrient summed over that
     * day's meals, keyed 'Y-m-d' in date order. A day appears only if at least one
     * meal on it has a nutrition row; a nutrient no meal reported is null (SQL SUM
     * skips NULLs). DailyIntake builds every screen's intake from this.
     *
     * @return array<string, array<string, float|null>>
     */
    public function dailyNutrientTotals(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $columns = [];
        foreach (NutritionData::FIELDS as $field) {
            $columns[$field] = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DATE(m.logged_at) AS day, '
            . implode(', ', array_map(static fn (string $col): string => "SUM(n.$col) AS $col", $columns)) . '
             FROM nutrition_data n
             JOIN meal_entry m ON m.id = n.meal_entry_id
             WHERE m.user_id = :user
               AND m.logged_at >= :from
               AND m.logged_at < :to
             GROUP BY DATE(m.logged_at)
             ORDER BY DATE(m.logged_at) ASC',
            [
                'user' => $user->getId(),
                'from' => $from->format('Y-m-d H:i:s'),
                'to'   => $to->format('Y-m-d H:i:s'),
            ],
        );

        $days = [];
        foreach ($rows as $row) {
            foreach ($columns as $field => $col) {
                $days[(string) $row['day']][$field] = $row[$col] !== null ? (float) $row[$col] : null;
            }
        }

        return $days;
    }
}

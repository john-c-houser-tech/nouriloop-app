<?php

declare(strict_types=1);

namespace App\Tests\Intake;

use App\Entity\Supplement;

/** Active supplements for a stubbed SupplementRepository::findActive(). */
trait StubsSupplements
{
    /**
     * One active supplement with these daily amounts, added on $since (long ago
     * by default, so it counts on every test day).
     *
     * @param array<string, float> $daily
     *
     * @return list<Supplement>
     */
    private static function takenSince(array $daily, string $since = '2000-01-01'): array
    {
        if ($daily === []) {
            return [];
        }

        return [(new Supplement())->setNutrients($daily)->setCreatedAt(new \DateTimeImmutable($since . ' 12:00'))];
    }
}

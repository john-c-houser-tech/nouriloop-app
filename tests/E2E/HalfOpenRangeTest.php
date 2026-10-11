<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\HealthMetric;
use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Enum\HealthMetricType;
use App\Enum\MealType;
use App\Intake\DailyIntake;
use App\Intake\IntakeDay;
use App\Repository\HealthMetricRepository;
use App\Repository\MealEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Day-range queries take a half-open [from, to): callers pass `to` = the next
 * day's start (UserTime::startOfTomorrow, the next window's start), so a row at
 * exactly that midnight belongs to the next day only. They compared `<= :to`
 * until 2026-09-30, double-counting such rows (today's dashboard, yesterday's
 * calorie digest, a goal window's "today excluded"). One query per repository.
 */
final class HalfOpenRangeTest extends WebTestCase
{
    use DbResetTrait;

    private const DAY = '2026-06-15 00:00:00';
    private const NEXT = '2026-06-16 00:00:00';

    public function testARowAtTheNextMidnightBelongsToTheNextDayOnly(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em);

        foreach (['2026-06-15 00:00:00', '2026-06-15 12:30:00', self::NEXT] as $at) {
            $meal = new MealEntry();
            $meal->setUser($user)->setMealType(MealType::Lunch)->setLoggedAt(new \DateTimeImmutable($at));
            $meal->setNutritionData(NutritionData::fromArray(['calories' => 500, 'confidenceScore' => 0.9]));
            $em->persist($meal);

            $metric = new HealthMetric();
            $metric->setUser($user)
                ->setMetricType(HealthMetricType::Steps)
                ->setValue(1000.0)
                ->setRecordedAt(new \DateTimeImmutable($at))
                ->setSource('test');
            $em->persist($metric);
        }
        $em->flush();

        $from = new \DateTimeImmutable(self::DAY);
        $to = new \DateTimeImmutable(self::NEXT);
        $c = static::getContainer();

        self::assertCount(2, $c->get(MealEntryRepository::class)->findWithNutritionForRange($user, $from, $to));
        self::assertSame(
            ['2026-06-15' => 1000.0],
            array_map(
                static fn (IntakeDay $d): ?float => $d->total('calories'),
                $c->get(DailyIntake::class)->days($user, $from, $to),
            ),
        );
        $buckets = $c->get(HealthMetricRepository::class)
            ->getAggregates($user, HealthMetricType::Steps, $from, $to, 'day');
        self::assertSame(['2026-06-15'], array_column($buckets, 'bucket'));
    }

    private function seedUser(EntityManagerInterface $em): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('ranges@test.local');
        $user->setDisplayName('Ranges');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}

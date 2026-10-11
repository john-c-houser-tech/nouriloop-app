<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\HealthMetric;
use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Enum\HealthMetricType;
use App\Enum\MealType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The dashboard's query count must not grow with the number of meals logged
 * today: nutrition is fetch-joined, the inflammation score reuses the loaded
 * meals, and glucose responses come from one batched query. Guards against the
 * per-meal lazy loads coming back.
 */
final class DashboardQueryCountTest extends WebTestCase
{
    use DbResetTrait;

    public function testQueryCountIsFlatInTheNumberOfMeals(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // one kernel, so $em stays the one requests use
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em);
        $userId = (int) $user->getId();
        $client->loginUser($user);
        // Warm-up: the first request shares the kernel the seeding ran on, so its
        // profile would also count the truncate/insert queries.
        $client->request('GET', '/');

        $this->seedMeal($em, $userId, 0);
        $one = $this->dashboardQueries($client);

        for ($i = 1; $i <= 4; $i++) {
            $this->seedMeal($em, $userId, $i);
        }
        $five = $this->dashboardQueries($client);

        self::assertSame($one, $five, 'five meals must cost the same queries as one');
    }

    private function dashboardQueries(KernelBrowser $client): int
    {
        $client->enableProfiler();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertNotFalse($profile);

        return $profile->getCollector('db')->getQueryCount();
    }

    /** A meal early today (user-local) with nutrition, plus a glucose reading after it. */
    private function seedMeal(EntityManagerInterface $em, int $userId, int $n): void
    {
        // Re-fetched each time: the entity manager is cleared after every request.
        $user = $em->find(User::class, $userId);
        self::assertNotNull($user);

        $today = (new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())))->setTime(0, 5);
        $at = $today->modify(sprintf('+%d minutes', $n * 20));

        $meal = new MealEntry();
        $meal->setUser($user)->setMealType(MealType::Breakfast)->setLoggedAt($at)->setIngredients([]);
        $meal->setNutritionData(
            NutritionData::fromArray(['calories' => 400, 'protein' => 20, 'confidenceScore' => 0.9]),
        );
        $em->persist($meal);

        $reading = new HealthMetric();
        $reading->setUser($user)
            ->setMetricType(HealthMetricType::BloodGlucose)
            ->setValue(110.0)
            ->setRecordedAt($at->modify('+10 minutes'))
            ->setSource('test');
        $em->persist($reading);
        $em->flush();
    }

    private function seedUser(EntityManagerInterface $em): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('dashboard@test.local');
        $user->setDisplayName('Dash');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setOnboardingDismissed(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}

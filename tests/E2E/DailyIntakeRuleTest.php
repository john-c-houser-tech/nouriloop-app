<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\Supplement;
use App\Entity\User;
use App\Enum\MealType;
use App\Service\UserTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * ADR 0002 on real screens: an active supplement counts toward intake only on a
 * day with a meal logged. Until 2026-10-06 the Dashboard counted its calories
 * on an empty day and the supplements page drew gaps from it alone.
 */
final class DailyIntakeRuleTest extends WebTestCase
{
    use DbResetTrait;

    public function testASupplementAloneIsNoIntake(): void
    {
        [$client] = $this->userWithAShake();

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.fw-bold.fs-3.text-success', 'no calories card without a meal');

        $crawler = $client->request('GET', '/supplements');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.bi-clipboard-data'), 'the no-meals state, not gaps');
    }

    public function testOnceAMealIsLoggedTheSupplementCountsOnTop(): void
    {
        [$client, $em, $user] = $this->userWithAShake();
        $meal = new MealEntry();
        $meal->setUser($user);
        $meal->setMealType(MealType::Lunch);
        // loggedAt is the user's wall-clock; the entity's default is the server's,
        // which is already tomorrow for this user between 00:00 and 07:00 UTC.
        $meal->setLoggedAt(static::getContainer()->get(UserTime::class)->now($user));
        $meal->setNutritionData(NutritionData::fromArray(['calories' => 500.0, 'protein' => 30.0]));
        $em->persist($meal);
        $em->flush();

        $crawler = $client->request('GET', '/');

        $calories = trim($crawler->filter('.fw-bold.fs-3.text-success')->text());
        self::assertSame('620', $calories, '500 + the 120 kcal shake');
    }

    /** @return array{KernelBrowser, EntityManagerInterface, User} */
    private function userWithAShake(): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('shake@test.local');
        $user->setDisplayName('Shake');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setOnboardingDismissed(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);

        $shake = new Supplement();
        $shake->setUser($user);
        $shake->setName('Protein shake');
        $shake->setNutrients(['calories' => 120.0, 'protein' => 25.0]);
        $em->persist($shake);
        $em->flush();

        $client->loginUser($user);

        return [$client, $em, $user];
    }
}

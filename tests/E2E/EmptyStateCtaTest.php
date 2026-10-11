<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The E18 empty-state fixes, end-to-end against the real DB (in-process
 * WebTestCase — kept in the e2e job for the DB, no browser): no page a new
 * user lands on is a dead end. Favorites gets a "log a meal" CTA, Health
 * Metrics gets both on-ramps inside the empty block, and the Supplements
 * no-meals state uses a neutral icon (a green check would read as "done").
 */
final class EmptyStateCtaTest extends WebTestCase
{
    use DbResetTrait;

    public function testEmptyFavoritesOffersLogAMealCta(): void
    {
        $client = $this->clientWithFreshUser('fav-empty@test.local');

        $crawler = $client->request('GET', '/meal/favorites');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Log a meal to get started', $crawler->html());
        self::assertGreaterThan(
            0,
            $crawler->filter('.card a[href$="/meal/log"]')->count(),
            'the empty card links to the meal log',
        );
    }

    public function testEmptyHealthMetricsOffersImportAndCgmCtas(): void
    {
        $client = $this->clientWithFreshUser('health-empty@test.local');

        $crawler = $client->request('GET', '/health-metrics');
        self::assertResponseIsSuccessful();
        $emptyCard = $crawler->filter('div.card.p-5');
        self::assertGreaterThan(0, $emptyCard->count(), 'the empty block renders');
        self::assertSame(1, $emptyCard->filter('a[href$="/health-metrics/import"]')->count());
        self::assertSame(1, $emptyCard->filter('a[href$="/connect-cgm"]')->count());
    }

    public function testSupplementsNoMealsStateUsesANeutralIconNotADoneCheck(): void
    {
        $client = $this->clientWithFreshUser('supp-empty@test.local');

        $crawler = $client->request('GET', '/supplements');
        self::assertResponseIsSuccessful();
        $emptyCard = $crawler->filter('div.card.p-5');
        self::assertGreaterThan(0, $emptyCard->count(), 'the empty block renders');
        self::assertSame(0, $emptyCard->filter('.bi-check-circle')->count(), 'no "done" check with zero meals');
        self::assertSame(1, $emptyCard->filter('.bi-clipboard-data')->count(), 'neutral icon instead');
    }

    public function testSupplementsStaleWindowShowsNoDataStateNotNoGaps(): void
    {
        $client = $this->clientWithFreshUser('supp-stale@test.local');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'supp-stale@test.local']);

        // A meal exists — but outside the 7-day window. An empty gap list here
        // means "no data in this window", never "no gaps": claiming full RDA
        // coverage from an empty window would be false (Copilot catch on PR #169).
        $meal = new \App\Entity\MealEntry();
        $meal->setUser($user);
        $meal->setMealType(\App\Enum\MealType::from('lunch'));
        $meal->setLoggedAt(new \DateTimeImmutable('-30 days'));
        $meal->setNutritionData(\App\Entity\NutritionData::fromArray(['calories' => 650.0, 'protein' => 40.0]));
        $em->persist($meal);
        $em->flush();
        // Drop the identity map so the request re-hydrates the user with a real
        // PersistentCollection (the in-memory User still has an empty ArrayCollection).
        $em->clear();

        $crawler = $client->request('GET', '/supplements?days=7');
        self::assertResponseIsSuccessful();
        $emptyCard = $crawler->filter('div.card.p-5');
        self::assertGreaterThan(0, $emptyCard->count(), 'the empty block renders');
        self::assertSame(0, $emptyCard->filter('.bi-check-circle')->count(), 'no "no gaps" check from an empty window');
        self::assertStringContainsString('No meals logged in the last 7 days', $emptyCard->text());
    }

    private function clientWithFreshUser(string $email): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Empty State Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $client;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Favorite;
use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Enum\MealType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Meal history Phase 1 (in-process, real DB): the history list with filters +
 * pagination, favorite-from-history reusing the stored analysis (zero Claude
 * calls — the whole point of Phase 1), and the zero-recalculation edits (meal
 * type / notes / same-day time) with their ownership + future-time guards.
 */
final class MealHistoryTest extends WebTestCase
{
    use DbResetTrait;

    public function testHistoryListsOwnMealsNewestFirstAndDetailIsOwnershipChecked(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $owner = $this->seedUser($em, 'owner@test.local');
        $rival = $this->seedUser($em, 'rival@test.local');
        $this->seedMeal($em, $owner, MealType::Breakfast, '-2 days', 'Oldest oatmeal');
        $this->seedMeal($em, $owner, MealType::Lunch, '-1 day', 'Middle salad');
        $newest = $this->seedMeal($em, $owner, MealType::Dinner, '-2 hours', 'Newest curry');
        $rivalMeal = $this->seedMeal($em, $rival, MealType::Lunch, '-1 day', 'Rival burger');

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/meal/history');
        $this->assertResponseIsSuccessful();

        $html = $crawler->html();
        self::assertStringContainsString('Newest curry', $html);
        self::assertStringContainsString('Middle salad', $html);
        self::assertStringContainsString('Oldest oatmeal', $html);
        self::assertStringNotContainsString('Rival burger', $html, 'another user\'s meals never appear');

        // Newest first.
        $rows = $crawler->filter('.list-group-item')->each(static fn ($node) => $node->text());
        self::assertStringContainsString('Newest curry', $rows[0]);
        self::assertStringContainsString('Oldest oatmeal', $rows[2]);

        // Rows link to the detail view; someone else's detail is a 404.
        self::assertSame(1, $crawler->filter('a[href="/meal/' . $newest->getId() . '"]')->count());
        $client->request('GET', '/meal/' . $rivalMeal->getId());
        $this->assertResponseStatusCodeSame(404);
        $client->request('GET', '/meal/' . $rivalMeal->getId() . '/edit');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testFiltersNarrowByTypeAndDateRange(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'filters@test.local');
        $this->seedMeal($em, $user, MealType::Breakfast, '-10 days', 'Old breakfast');
        $this->seedMeal($em, $user, MealType::Lunch, '-2 days', 'Recent lunch');
        $this->seedMeal($em, $user, MealType::Dinner, '-2 hours', 'Tonight dinner');
        $client->loginUser($user);

        $html = $client->request('GET', '/meal/history?type=lunch')->html();
        self::assertStringContainsString('Recent lunch', $html);
        self::assertStringNotContainsString('Old breakfast', $html);
        self::assertStringNotContainsString('Tonight dinner', $html);

        $day = (new \DateTimeImmutable('-10 days'))->format('Y-m-d');
        $html = $client->request('GET', '/meal/history?from=' . $day . '&to=' . $day)->html();
        self::assertStringContainsString('Old breakfast', $html, 'the "to" day is inclusive');
        self::assertStringNotContainsString('Recent lunch', $html);

        // Junk filter values are ignored, not errors.
        $client->request('GET', '/meal/history?type=elevenses&from=junk&page=99');
        $this->assertResponseIsSuccessful();
    }

    public function testPaginationSplitsAndCarriesFilters(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'pages@test.local');
        for ($i = 1; $i <= 27; $i++) {
            $this->seedMeal($em, $user, MealType::Lunch, sprintf('-%d hours', $i * 3), 'Lunch number ' . $i);
        }
        $this->seedMeal($em, $user, MealType::Dinner, '-1 hour', 'The one dinner');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/meal/history?type=lunch');
        self::assertCount(25, $crawler->filter('.list-group-item'));

        // The pagination links carry the active filter.
        $next = $crawler->filter('.pagination a')->last()->attr('href');
        self::assertStringContainsString('type=lunch', (string) $next);
        self::assertStringContainsString('page=2', (string) $next);

        $crawler = $client->request('GET', (string) $next);
        self::assertCount(2, $crawler->filter('.list-group-item'));
        self::assertStringNotContainsString('The one dinner', $crawler->html(), 'filter survived the page boundary');
    }

    public function testFavoriteFromHistoryReusesStoredAnalysisWithoutClaudeCall(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'fav@test.local');
        $meal = $this->seedMeal($em, $user, MealType::Lunch, '-3 days', 'Lentil soup with bread');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/meal/' . $meal->getId());
        $client->submit(
            $crawler->filter('form[action$="/meal/favorite/new"]')->form(['name' => 'Trusty lentils'])
        );
        $this->assertResponseRedirects('/meal/' . $meal->getId());

        $em->clear();
        $favorite = $em->getRepository(Favorite::class)->findOneBy([]);
        self::assertNotNull($favorite, 'a favorite was created from the stored meal');
        self::assertSame('Trusty lentils', $favorite->getName());
        self::assertSame(0, $favorite->getTokensPerAnalysis(), 'no per-analysis cost is known from history');
        self::assertEqualsWithDelta(430.0, (float) $favorite->getNutrients()['calories'], 0.01);

        // The whole point of Phase 1: no recalculation, no Claude call.
        $mock = static::getContainer()->get('test.anthropic_http_client');
        self::assertInstanceOf(MockHttpClient::class, $mock);
        self::assertSame(0, $mock->getRequestsCount(), 'favoriting a stored meal must not hit the API');
    }

    public function testEditUpdatesTypeNotesAndSameDayTimeAndReadsBackLive(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // Pin the user's local clock to ~noon (see zoneWithLocalNoon) so the
        // seeded 08:30 meal and the 07:15 correction are both safely in the
        // past regardless of when the suite runs.
        $zone = $this->zoneWithLocalNoon();
        $user = $this->seedUser($em, 'edit@test.local', $zone);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($zone));
        $meal = $this->seedMeal($em, $user, MealType::Breakfast, $today->setTime(8, 30), 'Editable eggs');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        self::assertSame(
            $today->format('Y-m-d') . 'T08:30',
            $crawler->filter('input[name="meal_edit_form[logged_at]"]')->attr('value'),
        );

        $client->submit($crawler->selectButton('Save Changes')->form([
            'meal_edit_form[mealType]'  => 'lunch',
            'meal_edit_form[logged_at]' => $today->format('Y-m-d') . 'T07:15',
            'meal_edit_form[notes]'     => 'actually ate this earlier',
        ]));
        $this->assertResponseRedirects('/meal/' . $meal->getId());

        $em->clear();
        $fresh = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertSame(MealType::Lunch, $fresh->getMealType());
        self::assertSame('actually ate this earlier', $fresh->getNotes());
        self::assertSame(
            $today->format('Y-m-d') . ' 07:15',
            $fresh->getLoggedAt()->format('Y-m-d H:i'),
            'time changed, day unchanged',
        );

        // Live-read check: the dashboard (today view) reflects the edit
        // immediately — no recompute step exists or is needed.
        $html = $client->request('GET', '/')->html();
        self::assertStringContainsString('7:15', $html);
    }

    public function testEditRejectsAFutureTimeAndKeepsTheStoredValue(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $zone = $this->zoneWithLocalNoon();
        $user = $this->seedUser($em, 'future@test.local', $zone);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($zone));
        $meal = $this->seedMeal($em, $user, MealType::Breakfast, $today->setTime(8, 30), 'Stays put');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/edit');
        $client->submit($crawler->selectButton('Save Changes')->form([
            'meal_edit_form[logged_at]' => $today->format('Y-m-d') . 'T20:45',
        ]));
        $this->assertResponseStatusCodeSame(422, 'invalid form re-renders as 422');
        self::assertStringContainsString('That date and time is in the future.', $client->getCrawler()->html());

        $em->clear();
        $fresh = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertSame('08:30', $fresh->getLoggedAt()->format('H:i'), 'rejected edit changed nothing');
    }

    public function testEditIsOwnershipChecked(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $owner = $this->seedUser($em, 'victim@test.local');
        $meal = $this->seedMeal($em, $owner, MealType::Lunch, '-1 day', 'Private lunch');
        $client->loginUser($this->seedUser($em, 'attacker@test.local'));

        $client->request('GET', '/meal/' . $meal->getId() . '/edit');
        $this->assertResponseStatusCodeSame(404);

        $client->request('POST', '/meal/' . $meal->getId() . '/edit', [
            'meal_edit_form' => ['mealType' => 'snack', 'logged_at' => '2026-01-01T01:00', 'notes' => 'hacked'],
        ]);
        $this->assertResponseStatusCodeSame(404);

        $em->clear();
        $fresh = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertSame(MealType::Lunch, $fresh->getMealType());
        self::assertNull($fresh->getNotes());
    }

    /**
     * An Etc/GMT* zone whose local wall-clock is currently ~noon, so tests can
     * seed morning meals and submit morning/evening times with a deterministic
     * past/future outcome at any real-world run time. (Etc zones invert the
     * sign: Etc/GMT-9 means UTC+9.)
     */
    private function zoneWithLocalNoon(): string
    {
        $utcHour = (int) (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('G');
        $offset = 12 - $utcHour;
        if ($offset > 14) {
            $offset -= 24;
        }
        if ($offset < -12) {
            $offset += 24;
        }

        return $offset >= 0 ? 'Etc/GMT-' . $offset : 'Etc/GMT+' . abs($offset);
    }

    private function seedMeal(
        EntityManagerInterface $em,
        User $user,
        MealType $type,
        \DateTimeImmutable|string $loggedAt,
        string $description,
    ): MealEntry {
        $entry = new MealEntry();
        $entry->setUser($user);
        $entry->setMealType($type);
        $entry->setLoggedAt(\is_string($loggedAt) ? new \DateTimeImmutable($loggedAt) : $loggedAt);
        $entry->setAiDescription($description);
        // The second row deliberately has no amount/unit — stored ingredient
        // rows aren't guaranteed complete (the demo seeder omits unit), and the
        // photo-free show page must not 500 on one (strict_variables).
        $entry->setIngredients([
            ['name' => 'lentils', 'amount' => '1', 'unit' => 'cup'],
            ['name' => 'olive oil'],
        ]);
        $entry->setNutritionData(NutritionData::fromArray([
            'calories'      => 430,
            'protein'       => 22,
            'carbohydrates' => 60,
            'fat'           => 9,
            'confidenceScore' => 0.9,
        ]));

        $em->persist($entry);
        $em->flush();

        return $entry;
    }

    private function seedUser(EntityManagerInterface $em, string $email, ?string $timezone = null): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('History Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        if ($timezone !== null) {
            $user->setTimezone($timezone);
        }
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Enum\GoalMetric;
use App\Enum\MealType;
use App\Service\AnalysisAllowance;
use App\Service\GoalMetricCalculator;
use App\Service\InflammationScoreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Meal history Phase 2 (in-process, real DB): manual nutrient edits with the
 * provenance stamp, the one-shot portion rescale, cross-day moves behind the
 * confirmation, and the plan's two named boundary checks — day bucketing for
 * the goal calculator and the inflammation/correlation inputs (both were built
 * while "meals never move" was true), plus the delete/allowance posture pin.
 */
final class MealEditPhase2Test extends WebTestCase
{
    use DbResetTrait;

    public function testNutrientEditAppliesLiveAndStampsProvenance(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $zone = $this->zoneWithLocalNoon();
        $user = $this->seedUser($em, 'nutrients@test.local', $zone);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($zone));
        $meal = $this->seedMeal($em, $user, $today->setTime(8, 30));
        $client->loginUser($user);

        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/edit');
        self::assertSame(
            '430',
            $crawler->filter('input[name="meal_edit_form[calories]"]')->attr('value'),
            'nutrient fields prefill from the stored values',
        );

        $client->submit($crawler->selectButton('Save Changes')->form([
            'meal_edit_form[calories]' => '550',
            'meal_edit_form[fiber]'    => '12',
        ]));
        $this->assertResponseRedirects('/meal/' . $meal->getId());

        $em->clear();
        $fresh = $em->getRepository(MealEntry::class)->find($meal->getId());
        $n = $fresh->getNutritionData();
        self::assertSame(550.0, $n->getCalories());
        self::assertSame(12.0, $n->getFiber());
        self::assertSame(22.0, $n->getProtein(), 'untouched prefilled field stays as stored');
        self::assertNotNull($fresh->getNutritionEditedAt(), 'nutrition provenance stamped');
        self::assertNotNull($fresh->getEditedAt(), 'edit trust marker stamped');

        // Provenance display: the confidence bar no longer describes the
        // numbers, so the show page swaps it for the edited-by-you marker.
        $html = $client->followRedirect()->html();
        self::assertStringContainsString('Edited by you', $html);
        self::assertStringNotContainsString('AI confidence:', $html);

        // Live-read check: the dashboard reflects the correction immediately —
        // nothing downstream caches meal aggregates.
        self::assertStringContainsString('550', $client->request('GET', '/')->html());
    }

    public function testUntouchedResaveAndBlankedFieldAreNoOps(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $zone = $this->zoneWithLocalNoon();
        $user = $this->seedUser($em, 'noop@test.local', $zone);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($zone));
        $meal = $this->seedMeal($em, $user, $today->setTime(8, 30));
        $client->loginUser($user);

        // Blanking a field keeps the stored value (never nulls it), and a save
        // that changes nothing stamps nothing — no false "Edited" markers.
        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/edit');
        $client->submit($crawler->selectButton('Save Changes')->form([
            'meal_edit_form[calories]' => '',
        ]));
        $this->assertResponseRedirects('/meal/' . $meal->getId());

        $em->clear();
        $fresh = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertSame(430.0, $fresh->getNutritionData()->getCalories(), 'blank keeps the stored value');
        self::assertNull($fresh->getNutritionEditedAt());
        self::assertNull($fresh->getEditedAt());
    }

    public function testPortionRescaleScalesNutritionAndNumericIngredients(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $zone = $this->zoneWithLocalNoon();
        $user = $this->seedUser($em, 'portions@test.local', $zone);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($zone));
        $meal = $this->seedMeal($em, $user, $today->setTime(8, 30));
        $client->loginUser($user);

        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/edit');
        $client->submit($crawler->selectButton('Save Changes')->form([
            'meal_edit_form[portions]' => '0.5',
        ]));
        $this->assertResponseRedirects('/meal/' . $meal->getId());

        $em->clear();
        $fresh = $em->getRepository(MealEntry::class)->find($meal->getId());
        $n = $fresh->getNutritionData();
        self::assertSame(215.0, $n->getCalories());
        self::assertSame(11.0, $n->getProtein());
        self::assertSame(0.9, $n->getConfidenceScore(), 'confidence is not a quantity and never scales');

        // PortionScaler semantics carry over: numeric amounts scale, an
        // amount-less ingredient row passes through untouched.
        $ingredients = $fresh->getIngredients();
        self::assertSame('0.5', $ingredients[0]['amount']);
        self::assertArrayNotHasKey('amount', $ingredients[1]);
        self::assertNotNull($fresh->getNutritionEditedAt(), 'a rescale is a manual nutrition edit');
    }

    public function testCrossDayMoveRequiresConfirmationAndRebucketsDownstream(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $zone = $this->zoneWithLocalNoon();
        $user = $this->seedUser($em, 'move@test.local', $zone);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($zone));
        $oldDay = $today->modify('-1 day')->setTime(18, 0);
        $newDay = $today->modify('-3 days')->setTime(18, 0);
        $meal = $this->seedMeal($em, $user, $oldDay);
        $client->loginUser($user);

        // Without the confirmation checkbox, a cross-day move is refused and
        // nothing changes — the plan's deliberate speed bump.
        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/edit');
        $client->submit($crawler->selectButton('Save Changes')->form([
            'meal_edit_form[logged_at]' => $newDay->format('Y-m-d\TH:i'),
        ]));
        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'Please confirm moving this meal to a different day.',
            $client->getCrawler()->html(),
        );

        $em->clear();
        $unchanged = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertSame($oldDay->format('Y-m-d'), $unchanged->getLoggedAt()->format('Y-m-d'));

        // Confirmed: the move lands.
        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/edit');
        $client->submit($crawler->selectButton('Save Changes')->form([
            'meal_edit_form[logged_at]'          => $newDay->format('Y-m-d\TH:i'),
            'meal_edit_form[confirm_day_change]' => '1',
        ]));
        $this->assertResponseRedirects('/meal/' . $meal->getId());

        $em->clear();
        $moved = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertSame($newDay->format('Y-m-d H:i'), $moved->getLoggedAt()->format('Y-m-d H:i'));
        self::assertNotNull($moved->getEditedAt());
        self::assertNull($moved->getNutritionEditedAt(), 'a move is not a nutrition edit');

        // The plan's two named boundary checks — both subsystems bucket by the
        // meal's local day and were built while "meals never move" was true:
        //
        // (a) GoalMetricCalculator (check-in windows): the day series follows
        // the move on the very next read — no stale bucket under the old day.
        $freshUser = $em->getRepository(User::class)->find($user->getId());
        $calculator = static::getContainer()->get(GoalMetricCalculator::class);
        $daily = $calculator->dailyValues(
            $freshUser,
            GoalMetric::Calories,
            $today->modify('-7 days')->setTime(0, 0),
            $today->setTime(23, 59),
        );
        self::assertArrayHasKey($newDay->format('Y-m-d'), $daily);
        self::assertArrayNotHasKey($oldDay->format('Y-m-d'), $daily);
        self::assertSame(430.0, $daily[$newDay->format('Y-m-d')]);

        // (b) The inflammation daily series (CorrelationAnalyzer's score-by-date
        // input — its same-day/next-day lag alignment keys off these dates):
        // the score bucket moves with the meal, so lagged correlations align
        // against the corrected day, not the original one.
        $scores = static::getContainer()->get(InflammationScoreService::class)->dailyScores(
            $freshUser,
            $today->modify('-7 days')->setTime(0, 0),
            $today->setTime(23, 59),
        );
        self::assertArrayHasKey($newDay->format('Y-m-d'), $scores);
        self::assertArrayNotHasKey($oldDay->format('Y-m-d'), $scores);
    }

    public function testNutritionLessMealEditsWithoutNutrientFields(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $zone = $this->zoneWithLocalNoon();
        $user = $this->seedUser($em, 'legacy@test.local', $zone);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($zone));
        $meal = $this->seedMeal($em, $user, $today->setTime(8, 30), withNutrition: false);
        $client->loginUser($user);

        // No NutritionData row → the nutrient/portion fields are omitted from
        // the form entirely (include_nutrition) — otherwise form_end()'s
        // leftover dump would render them unstyled and controller-ignored
        // (Copilot catch on PR #177).
        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="meal_edit_form[calories]"]'));
        self::assertCount(0, $crawler->filter('input[name="meal_edit_form[portions]"]'));

        // The Phase-1 edits still work for such a meal.
        $client->submit($crawler->selectButton('Save Changes')->form([
            'meal_edit_form[mealType]' => 'snack',
        ]));
        $this->assertResponseRedirects('/meal/' . $meal->getId());

        $em->clear();
        $fresh = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertSame(MealType::Snack, $fresh->getMealType());
        self::assertNull($fresh->getNutritionEditedAt());
    }

    public function testDeleteRefundsTheMonthlyAllowanceAcceptedTradeoff(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // Pins the plan's confirmed decision on the log-then-delete question:
        // the Free allowance is DERIVED from meals logged this month (the
        // CheckinAllowance pattern — no call-time ledger), so deleting a meal
        // refunds its slot even though the Claude call happened. Accepted, same
        // posture as recipe imports and analyzed-but-never-saved meals; revisit
        // with a usage ledger only if abuse appears. This test documents the
        // semantics — it is not a guarantee to preserve them.
        $user = $this->seedUser($em, 'allowance@test.local');
        self::assertNull($user->getCompTier(), 'must be a plain Free user for the allowance to apply');

        // Seed at the user-local START of today + i minutes: always inside the
        // current local month (a relative "-N minutes" could cross the month
        // boundary in the first minutes of the 1st — Copilot catch on PR #177;
        // countForUserThisMonth has no future exclusion, so 00:0N is fine).
        $todayStart = (new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())))->setTime(0, 0);
        $meals = [];
        for ($i = 0; $i < AnalysisAllowance::FREE_MONTHLY_LIMIT; $i++) {
            $meals[] = $this->seedMeal($em, $user, $todayStart->modify(sprintf('+%d minutes', $i)));
        }

        $allowance = static::getContainer()->get(AnalysisAllowance::class);
        self::assertSame(0, $allowance->remaining($user));

        $client->loginUser($user);
        $crawler = $client->request('GET', '/meal/' . $meals[0]->getId());
        $client->submit($crawler->filter('form[action$="/delete"]')->form());
        $this->assertResponseRedirects('/');

        self::assertSame(1, $allowance->remaining($user), 'deleting a meal refunds its allowance slot');
    }

    /**
     * An Etc/GMT* zone whose local wall-clock is currently ~noon (same helper
     * as MealHistoryTest), so seeded morning/evening times are deterministic
     * at any real-world run time.
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
        \DateTimeImmutable|string $loggedAt,
        bool $withNutrition = true,
    ): MealEntry {
        $entry = new MealEntry();
        $entry->setUser($user);
        $entry->setMealType(MealType::Lunch);
        $entry->setLoggedAt(\is_string($loggedAt) ? new \DateTimeImmutable($loggedAt) : $loggedAt);
        $entry->setAiDescription('Lentil soup with bread');
        // The second row deliberately has no amount — the rescale must pass it
        // through untouched (PortionScaler semantics).
        $entry->setIngredients([
            ['name' => 'lentils', 'amount' => '1', 'unit' => 'cup'],
            ['name' => 'olive oil'],
        ]);
        if ($withNutrition) {
            $entry->setNutritionData(NutritionData::fromArray([
                'calories'        => 430,
                'protein'         => 22,
                'carbohydrates'   => 60,
                'fat'             => 9,
                'confidenceScore' => 0.9,
            ]));
        }

        $em->persist($entry);
        $em->flush();

        return $entry;
    }

    private function seedUser(EntityManagerInterface $em, string $email, ?string $timezone = null): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Phase Two Tester');
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

<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GoalDefinition;
use App\Entity\ApiToken;
use App\Entity\Favorite;
use App\Entity\GoalCheckin;
use App\Entity\HealthMetric;
use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\Supplement;
use App\Entity\SymptomDay;
use App\Entity\SymptomEntry;
use App\Entity\User;
use App\Enum\GoalAggregation;
use App\Enum\GoalCheckinStatus;
use App\Enum\GoalDirection;
use App\Enum\GoalFrequency;
use App\Enum\GoalMetric;
use App\Enum\GoalOutputFormat;
use App\Enum\HealthMetricType;
use App\Enum\MealType;
use App\Enum\NotificationChannel;
use App\Enum\SubscriptionTier;
use App\Enum\UnitSystem;
use App\Repository\SymptomTypeRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * (Re)builds a single, realistic **demo user** with ~60 days of data across every
 * feature — the reusable fixture behind the help screenshots (and a handy local
 * review/test account). Idempotent: clears the demo user's prior data first.
 *
 * Deterministic (a seeded local RNG) so screenshots are stable between runs. Built as
 * a service (the project has no fixtures bundle) so a console command or the
 * screenshot command can call it directly.
 */
class DemoDataSeeder
{
    public const EMAIL = 'demo@nouriloop.com';
    public const PASSWORD = 'demopass123';
    public const DISPLAY_NAME = 'Alex Rivera';

    private const DAYS = 60;

    /** Seeded per run so data is deterministic without touching PHP's global RNG. */
    private Randomizer $rng;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly SymptomTypeManager $symptomTypes,
        private readonly SymptomTypeRepository $symptomTypeRepo,
        private readonly GoalFactory $goalFactory,
    ) {
    }

    /**
     * @return array<string, int> per-section row counts
     */
    public function seed(): array
    {
        // A local, seeded RNG — deterministic data across runs, without mutating the
        // process-global mt_rand() state.
        $this->rng = new Randomizer(new Mt19937(20260623));

        $user = $this->findOrCreateUser();
        $this->clear($user);
        $userId = $user->getId();
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->find($userId);
        \assert($user instanceof User);

        $midnight = new \DateTimeImmutable('today 00:00');

        $day = $this->seedMeals($user, $midnight);
        $this->em->flush();

        $counts = [
            'meals'       => $day['mealCount'],
            'favorites'   => $this->seedFavorites($user),
            'health'      => $this->seedHealthMetrics($user, $midnight, $day['indulgence']),
            'supplements' => $this->seedSupplements($user),
            'symptoms'    => $this->seedSymptoms($user, $midnight, $day['indulgence']),
            'api_tokens'  => $this->seedApiToken($user),
        ];
        $this->em->flush();

        $counts['goal_checkins'] = $this->seedGoalWithHistory($user, $midnight);
        $this->em->flush();

        return $counts;
    }

    private function findOrCreateUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        if ($user === null) {
            $user = new User();
            $user->setEmail(self::EMAIL);
            $user->setRoles(['ROLE_USER']);
            $this->em->persist($user);
        }
        $user->setDisplayName(self::DISPLAY_NAME);
        $user->setActive(true);
        $user->setTimezone('America/Los_Angeles');
        $user->setUnitSystem(UnitSystem::Imperial);
        $user->setNotificationChannel(NotificationChannel::Email);
        $user->setNotifyNoMeals(true);
        $user->setNotifySupplements(true);
        // A fresh/never-onboarded user still needsOnboarding(), so the floating "Get set
        // up" overlay would render on top of every page — irrelevant to a demo/review
        // account and exactly what the help screenshots don't want covered.
        $user->setOnboardingDismissed(true);
        // Verified, so the unverified-email banner doesn't cover screenshots and the
        // demo account exercises the scheduled-email paths.
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-60 days'));
        // Permanent Loop+ comp (decision 2026-07-22, previously pending): the demo
        // account is the standing review login and the screenshot subject — reviewers
        // and help/lp captures should see the product, not locked upsells or an
        // exhausted Free allowance. Also removes the ad-hoc comp step the lp-screenshot
        // recipe needed, and keeps the demo goal from hitting the Free check-in pause.
        $user->setCompTier(SubscriptionTier::LoopPlus);
        $user->setCompExpiresAt(null);
        $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
        $this->em->flush();

        return $user;
    }

    /**
     * Wipe the demo user's data, FK-safe and atomically (mirrors
     * AdminUserController::deleteUserAndData): portable `IN (SELECT …)` subqueries,
     * not vendor-specific multi-table DELETE…JOIN, all in one transaction.
     */
    private function clear(User $user): void
    {
        $id = $user->getId();
        $this->connection->transactional(function (Connection $conn) use ($id): void {
            $conn->executeStatement(
                'DELETE FROM goal_checkin WHERE goal_id IN (SELECT id FROM nutrition_goal WHERE user_id = ?)',
                [$id],
            );
            $conn->executeStatement('DELETE FROM nutrition_goal WHERE user_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM symptom_day WHERE user_id = ?', [$id]); // cascades symptom_entry
            $conn->executeStatement('DELETE FROM symptom_type WHERE user_id = ?', [$id]);
            $conn->executeStatement(
                'DELETE FROM nutrition_data WHERE meal_entry_id IN (SELECT id FROM meal_entry WHERE user_id = ?)',
                [$id],
            );
            // Before meal_entry: its FK is ON DELETE SET NULL, so these rows
            // would otherwise survive a reset as orphans and keep drawing on the
            // demo account's monthly analysis allowance.
            $conn->executeStatement('DELETE FROM analysis_event WHERE user_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM meal_entry WHERE user_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM favorite WHERE user_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM supplement WHERE user_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM health_metric WHERE user_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM api_token WHERE user_id = ?', [$id]);
        });
    }

    /**
     * One recently-used API token so the CGM setup wizard's verification ladder
     * screenshots fully green. The plaintext is random and discarded — the demo
     * account also serves as a review login, so a known-plaintext token would be
     * a real credential. (Non-deterministic bytes, but nothing visible varies.)
     */
    private function seedApiToken(User $user): int
    {
        $token = new ApiToken();
        $token->setUser($user);
        $token->setName('Health Auto Export');
        $token->setTokenHash(ApiTokenManager::hash(ApiTokenManager::generatePlaintext()));
        $token->setLastUsedAt(new \DateTimeImmutable('-5 minutes'));
        $this->em->persist($token);

        return 1;
    }

    // ── Meals ─────────────────────────────────────────────────────────────

    /**
     * @return array{mealCount: int, indulgence: array<int, float>}
     */
    private function seedMeals(User $user, \DateTimeImmutable $midnight): array
    {
        $tpl = $this->mealTemplates();
        $indulgence = [];
        $mealCount = 0;

        // Down to $d = 0 (today) so the Dashboard "Today" view + today's glucose card
        // render populated in help screenshots and the standing review account.
        for ($d = self::DAYS - 1; $d >= 0; $d--) {
            $date = $midnight->modify("-{$d} days");
            // A rough "clean vs indulgent" rhythm: weekends + the occasional weekday slip.
            $weekend = in_array((int) $date->format('N'), [6, 7], true);
            $indulgent = $weekend ? $this->rng->getInt(0, 100) < 75 : $this->rng->getInt(0, 100) < 30;

            $picks = $indulgent
                ? [$tpl['yogurt'], $tpl['burrito'], $this->rng->getInt(0, 1) ? $tpl['burger'] : $tpl['pizza']]
                : [$tpl['oatmeal'], $tpl['salmon'], $tpl['tofu']];

            $sum = 0.0;
            foreach ($picks as $i => $t) {
                $hour = [8, 13, 19][$i];
                $loggedAt = $date->setTime($hour, $this->rng->getInt(0, 45));
                $this->em->persist($this->mealFromTemplate($user, $t, $loggedAt));
                $sum += $t['indulgence'];
                $mealCount++;
            }
            $indulgence[$d] = $sum / count($picks);
        }

        return ['mealCount' => $mealCount, 'indulgence' => $indulgence];
    }

    /** @param array<string, mixed> $tpl */
    private function mealFromTemplate(User $user, array $tpl, \DateTimeImmutable $loggedAt): MealEntry
    {
        $meal = new MealEntry();
        $meal->setUser($user);
        $meal->setMealType($tpl['type']);
        $meal->setLoggedAt($loggedAt);
        $meal->setAiDescription($tpl['description']);
        $meal->setIngredients($tpl['ingredients']);

        $nutrition = NutritionData::fromArray($tpl['nutrients']);
        $nutrition->setMealEntry($meal);
        $meal->setNutritionData($nutrition);

        return $meal;
    }

    private function seedFavorites(User $user): int
    {
        $tpl = $this->mealTemplates();
        foreach (['oatmeal', 'salmon', 'burger'] as $key) {
            $t = $tpl[$key];
            $fav = new Favorite();
            $fav->setUser($user);
            $fav->setName($t['description']);
            $fav->setAiDescription($t['description']);
            $fav->setNutrients(NutritionData::fromArray($t['nutrients'])->toArray());
            $fav->setIngredients($t['ingredients']);
            $fav->setUseCount($this->rng->getInt(2, 9));
            $fav->setTokensPerAnalysis(1400);
            $fav->setLastUsedAt(new \DateTimeImmutable('-' . $this->rng->getInt(1, 6) . ' days'));
            $this->em->persist($fav);
        }

        $this->em->persist($this->importedRecipeFavorite($user));

        return 4;
    }

    /**
     * One recipe-photo import (badge + Ingredients & steps collapse on the
     * favorites card), so the review account and the help/favorites screenshots
     * show the import feature. Most recently used, so it sorts to the top of
     * the picker — the capture command opens the first card's collapse.
     */
    private function importedRecipeFavorite(User $user): Favorite
    {
        $batch = [
            ['name' => 'dried green lentils', 'amount' => '2', 'unit' => 'cups'],
            ['name' => 'yellow onion, chopped', 'amount' => '1', 'unit' => 'piece'],
            ['name' => 'carrots, diced', 'amount' => '3', 'unit' => 'piece'],
            ['name' => 'olive oil', 'amount' => '2', 'unit' => 'tbsp'],
            ['name' => 'vegetable broth', 'amount' => '8', 'unit' => 'cups'],
            ['name' => 'ground cumin', 'amount' => '2', 'unit' => 'tsp'],
            ['name' => 'salt', 'amount' => 'to taste', 'unit' => ''],
        ];

        $fav = new Favorite();
        $fav->setUser($user);
        $fav->setName("Grandma's Lentil Soup");
        $fav->setAiDescription("Grandma's Lentil Soup");
        $fav->setNutrients(NutritionData::fromArray([
            'calories' => 220, 'protein' => 13, 'carbohydrates' => 34, 'fat' => 4,
            'saturatedFat' => 0.6, 'fiber' => 9, 'sugar' => 4, 'sodium' => 380,
            'iron' => 3.4, 'potassium' => 480, 'vitaminA' => 210, 'vitaminC' => 6,
            'confidenceScore' => 0.82,
        ])->toArray());
        $fav->setIngredients([
            ['name' => 'dried green lentils', 'amount' => '0.25', 'unit' => 'cups'],
            ['name' => 'yellow onion, chopped', 'amount' => '0.13', 'unit' => 'piece'],
            ['name' => 'carrots, diced', 'amount' => '0.38', 'unit' => 'piece'],
            ['name' => 'olive oil', 'amount' => '0.25', 'unit' => 'tbsp'],
            ['name' => 'vegetable broth', 'amount' => '1', 'unit' => 'cups'],
            ['name' => 'ground cumin', 'amount' => '0.25', 'unit' => 'tsp'],
            ['name' => 'salt', 'amount' => 'to taste', 'unit' => ''],
        ]);
        $fav->setBatchIngredients($batch);
        $fav->setServingsAtImport(8.0);
        $fav->setInstructions(
            "1. Warm the olive oil in a large pot and soften the onion and carrots, about 5 minutes.\n"
            . "2. Stir in the cumin, then add the lentils and broth.\n"
            . "3. Bring to a boil, reduce the heat, and simmer 35-40 minutes until the lentils are tender.\n"
            . "4. Season with salt and serve. Keeps 4 days refrigerated; freezes well.",
        );
        $fav->setTokensPerAnalysis(2100);
        $fav->setUseCount($this->rng->getInt(3, 7));
        $fav->setLastUsedAt(new \DateTimeImmutable('-6 hours'));

        return $fav;
    }

    // ── Health metrics ────────────────────────────────────────────────────

    /** @param array<int, float> $indulgence */
    private function seedHealthMetrics(User $user, \DateTimeImmutable $midnight, array $indulgence): int
    {
        $count = 0;
        $add = function (HealthMetricType $type, \DateTimeImmutable $at, float $value) use ($user, &$count): void {
            $m = new HealthMetric();
            $m->setUser($user)->setMetricType($type)->setValue($value)->setRecordedAt($at)->setSource('demo');
            $this->em->persist($m);
            $count++;
        };

        $weight = 84.0; // kg, gently trending down with noise
        for ($d = self::DAYS - 1; $d >= 0; $d--) {
            $date = $midnight->modify("-{$d} days");
            $weight -= 0.03;
            $add(HealthMetricType::Weight, $date->setTime(7, 15), round($weight + $this->rng->getInt(-4, 4) / 10, 1));
            $add(HealthMetricType::Steps, $date->setTime(21, 0), (float) $this->rng->getInt(5500, 12500));
            if ($d % 7 === 0) {
                $bodyFat = round(24.0 - (self::DAYS - $d) * 0.01, 1);
                $add(HealthMetricType::BodyFatPercentage, $date->setTime(7, 16), $bodyFat);
            }
        }

        // Dense CGM glucose for the recent days (incl. today) so per-meal curves + rankings render.
        for ($d = 12; $d >= 0; $d--) {
            $date = $midnight->modify("-{$d} days");
            $spikes = [
                [8, 35 + $indulgence[$d] * 8],
                [13, 40 + $indulgence[$d] * 9],
                [19, 45 + $indulgence[$d] * 12],
            ];
            for ($min = 6 * 60; $min <= 22 * 60; $min += 15) {
                $value = 92.0;
                foreach ($spikes as [$h, $amp]) {
                    $dt = $min - $h * 60;
                    if ($dt >= 0 && $dt <= 150) {
                        $value += $amp * exp(-(($dt - 45) ** 2) / 1600); // peaks ~45 min after the meal
                    }
                }
                $at = $date->setTime(intdiv($min, 60), $min % 60);
                $add(HealthMetricType::BloodGlucose, $at, round($value + $this->rng->getInt(-3, 3), 1));
            }
        }

        return $count;
    }

    private function seedSupplements(User $user): int
    {
        $supps = [
            ['Vitamin D3 + K2', ['vitaminD' => 25.0, 'vitaminK' => 90.0]],
            ['B-Complex', ['vitaminB6' => 1.7, 'vitaminB9' => 400.0, 'vitaminB12' => 6.0]],
            ['Magnesium Glycinate', ['magnesium' => 200.0]],
        ];
        foreach ($supps as [$name, $nutrients]) {
            $s = new Supplement();
            $s->setUser($user)->setName($name)->setServingsPerDay(1.0)->setActive(true)->setNutrients($nutrients);
            // Taken for the whole demo history, so it counts on every seeded day.
            $s->setCreatedAt(new \DateTimeImmutable('-' . self::DAYS . ' days midnight'));
            $this->em->persist($s);
        }

        return count($supps);
    }

    // ── Symptoms (correlated with indulgent days) ─────────────────────────

    /** @param array<int, float> $indulgence */
    private function seedSymptoms(User $user, \DateTimeImmutable $midnight, array $indulgence): int
    {
        $this->symptomTypes->ensureDefaultsFor($user);
        $this->em->flush();

        // Enable joint pain (lower is better) + energy (higher is better) + joint stiffness
        // (lower is better; also used as the help-center's example symptom chart, so it needs
        // its own believable pattern rather than just mirroring joint pain).
        $jointPain = $this->symptomTypeRepo->findOneByUserAndKey($user, 'joint_pain');
        $energy = $this->symptomTypeRepo->findOneByUserAndKey($user, 'energy');
        $jointStiffness = $this->symptomTypeRepo->findOneByUserAndKey($user, 'joint_stiffness');
        $jointPain?->setEnabled(true);
        $energy?->setEnabled(true);
        $jointStiffness?->setEnabled(true);
        $this->em->flush();

        $count = 0;
        for ($d = 30; $d >= 0; $d--) {
            $score = $indulgence[$d] ?? 0.5; // 0 (clean) .. ~1 (indulgent)
            $sympDay = new SymptomDay();
            $sympDay->setUser($user)->setLoggedDate($midnight->modify("-{$d} days"));
            $this->em->persist($sympDay);

            if ($jointPain !== null) {
                $level = (int) max(0, min(10, round(2 + $score * 5 + $this->rng->getInt(-1, 1))));
                $entry = (new SymptomEntry())->setSymptomDay($sympDay)->setSymptomType($jointPain)->setLevel($level);
                $this->em->persist($entry);
                $count++;
            }
            if ($energy !== null) {
                $level = (int) max(0, min(10, round(8 - $score * 4 + $this->rng->getInt(-1, 1))));
                $entry = (new SymptomEntry())->setSymptomDay($sympDay)->setSymptomType($energy)->setLevel($level);
                $this->em->persist($entry);
                $count++;
            }
            if ($jointStiffness !== null) {
                // Stiffness lags a day behind the diet that causes it (unlike the same-day
                // joint-pain signal above), so the demo data exercises the panel's next-day
                // correlation, not just same-day.
                $laggedScore = $indulgence[$d + 1] ?? $score;
                $level = (int) max(0, min(10, round(3 + $laggedScore * 5 + $this->rng->getInt(-1, 1))));
                $entry = (new SymptomEntry())
                    ->setSymptomDay($sympDay)->setSymptomType($jointStiffness)->setLevel($level);
                $this->em->persist($entry);
                $count++;
            }
        }

        return $count;
    }

    // ── Goal + check-in history ──────────────────────────────────────────

    private function seedGoalWithHistory(User $user, \DateTimeImmutable $midnight): int
    {
        $def = new GoalDefinition(GoalMetric::Fiber, GoalDirection::AtLeast, GoalAggregation::DailyAverage, 25.0, 7);
        $goal = $this->goalFactory->create(
            $user,
            $def,
            'Boost Daily Fiber',
            GoalFrequency::Weekly,
            7,
            7,
            0,
            GoalOutputFormat::Chart,
        );

        // Trend keys match what the evaluator would stamp; the two null rows predate
        // the trend column in spirit (no hint rendered), which keeps the "held steady"
        // narrative from pairing with a "trending up" hint.
        $history = [
            [28, GoalCheckinStatus::OffTrack, 18.4, 'Fiber came in at 18.4 g/day — under your 25 g target. '
                . 'More beans and whole grains this week.', 'slipping'],
            [21, GoalCheckinStatus::OnTrack, 22.1, 'Up to 22.1 g/day — climbing toward 25 g. Nice momentum.',
                'improving'],
            [14, GoalCheckinStatus::Achieved, 26.8, 'You hit 26.8 g/day, clearing your 25 g target. '
                . 'Great week for fiber.', 'improving'],
            [7, GoalCheckinStatus::OnTrack, 24.2, 'Held at 24.2 g/day, just shy of target — steady and close.',
                null],
        ];
        foreach ($history as [$daysAgo, $status, $value, $text, $trend]) {
            $runAt = $midnight->modify("-{$daysAgo} days")->setTime(7, 0);
            $checkin = (new GoalCheckin())
                ->setGoal($goal)
                ->setRunAt($runAt)
                ->setWindowStart($runAt->modify('-7 days'))
                ->setWindowEnd($runAt)
                ->setStatus($status)
                ->setTrend($trend)
                ->setCurrentValue($value)
                ->setTargetValue(25.0)
                ->setSummaryText($text)
                ->setPromptVersion($goal->getPromptVersion());
            $this->em->persist($checkin);
        }

        return count($history);
    }

    /**
     * Meal "templates" — each a realistic meal with nutrition + ingredients and an
     * `indulgence` weight (0 clean … ~1 indulgent) that drives the inflammation
     * rhythm, the glucose spikes, and the symptom correlation.
     *
     * @return array<string, array{type: MealType, description: string, indulgence: float,
     *     ingredients: list<array{name: string, amount: string}>, nutrients: array<string, float>}>
     */
    private function mealTemplates(): array
    {
        return [
            'oatmeal' => [
                'type' => MealType::Breakfast,
                'description' => 'Oatmeal with blueberries and walnuts',
                'indulgence' => 0.1,
                'ingredients' => [
                    ['name' => 'rolled oats', 'amount' => '1 cup'],
                    ['name' => 'blueberries', 'amount' => '1/2 cup'],
                    ['name' => 'walnuts', 'amount' => '1 oz'],
                ],
                'nutrients' => [
                    'calories' => 360, 'protein' => 12, 'carbohydrates' => 58, 'fat' => 11,
                    'saturatedFat' => 1.5, 'fiber' => 10, 'sugar' => 12, 'sodium' => 120,
                    'vitaminC' => 8, 'vitaminE' => 4, 'calcium' => 180, 'iron' => 3,
                    'magnesium' => 130, 'potassium' => 420, 'zinc' => 2, 'confidenceScore' => 0.9,
                ],
            ],
            'salmon' => [
                'type' => MealType::Lunch,
                'description' => 'Grilled salmon with quinoa and spinach salad',
                'indulgence' => 0.05,
                'ingredients' => [
                    ['name' => 'salmon fillet', 'amount' => '5 oz'],
                    ['name' => 'quinoa', 'amount' => '1 cup'],
                    ['name' => 'spinach', 'amount' => '2 cups'],
                    ['name' => 'olive oil', 'amount' => '1 tbsp'],
                ],
                'nutrients' => [
                    'calories' => 540, 'protein' => 38, 'carbohydrates' => 42, 'fat' => 24,
                    'saturatedFat' => 4, 'fiber' => 8, 'sugar' => 5, 'sodium' => 360,
                    'vitaminA' => 280, 'vitaminC' => 18, 'vitaminD' => 14, 'vitaminE' => 6,
                    'calcium' => 120, 'iron' => 4, 'magnesium' => 150, 'potassium' => 780,
                    'zinc' => 3, 'selenium' => 45, 'confidenceScore' => 0.88,
                ],
            ],
            'tofu' => [
                'type' => MealType::Dinner,
                'description' => 'Stir-fried tofu with broccoli and brown rice',
                'indulgence' => 0.15,
                'ingredients' => [
                    ['name' => 'tofu', 'amount' => '6 oz'],
                    ['name' => 'broccoli', 'amount' => '1.5 cups'],
                    ['name' => 'bell pepper', 'amount' => '1'],
                    ['name' => 'brown rice', 'amount' => '1 cup'],
                    ['name' => 'sesame oil', 'amount' => '1 tsp'],
                ],
                'nutrients' => [
                    'calories' => 480, 'protein' => 24, 'carbohydrates' => 56, 'fat' => 17,
                    'saturatedFat' => 3, 'fiber' => 9, 'sugar' => 9, 'sodium' => 640,
                    'vitaminA' => 320, 'vitaminC' => 90, 'vitaminE' => 3, 'calcium' => 350,
                    'iron' => 5, 'magnesium' => 140, 'potassium' => 700, 'zinc' => 3,
                    'confidenceScore' => 0.86,
                ],
            ],
            'yogurt' => [
                'type' => MealType::Breakfast,
                'description' => 'Greek yogurt with granola and honey',
                'indulgence' => 0.4,
                'ingredients' => [
                    ['name' => 'greek yogurt', 'amount' => '1 cup'],
                    ['name' => 'granola', 'amount' => '1/2 cup'],
                    ['name' => 'honey', 'amount' => '1 tbsp'],
                ],
                'nutrients' => [
                    'calories' => 340, 'protein' => 20, 'carbohydrates' => 44, 'fat' => 9,
                    'saturatedFat' => 4, 'fiber' => 3, 'sugar' => 26, 'sodium' => 95,
                    'calcium' => 300, 'iron' => 1, 'potassium' => 280, 'zinc' => 1,
                    'confidenceScore' => 0.85,
                ],
            ],
            'burrito' => [
                'type' => MealType::Lunch,
                'description' => 'Chicken burrito bowl with rice and beans',
                'indulgence' => 0.55,
                'ingredients' => [
                    ['name' => 'grilled chicken', 'amount' => '5 oz'],
                    ['name' => 'white rice', 'amount' => '1 cup'],
                    ['name' => 'black beans', 'amount' => '1/2 cup'],
                    ['name' => 'cheese', 'amount' => '1 oz'],
                    ['name' => 'salsa', 'amount' => '2 tbsp'],
                ],
                'nutrients' => [
                    'calories' => 660, 'protein' => 42, 'carbohydrates' => 72, 'fat' => 22,
                    'saturatedFat' => 8, 'fiber' => 11, 'sugar' => 5, 'sodium' => 920,
                    'vitaminC' => 12, 'calcium' => 220, 'iron' => 5, 'magnesium' => 110,
                    'potassium' => 760, 'zinc' => 4, 'confidenceScore' => 0.82,
                ],
            ],
            'burger' => [
                'type' => MealType::Dinner,
                'description' => 'Bacon cheeseburger with fries',
                'indulgence' => 0.95,
                'ingredients' => [
                    ['name' => 'beef patty', 'amount' => '6 oz'],
                    ['name' => 'bacon', 'amount' => '2 strips'],
                    ['name' => 'cheddar cheese', 'amount' => '1 slice'],
                    ['name' => 'brioche bun', 'amount' => '1'],
                    ['name' => 'fried potatoes', 'amount' => '1 cup'],
                ],
                'nutrients' => [
                    'calories' => 960, 'protein' => 46, 'carbohydrates' => 74, 'fat' => 56,
                    'saturatedFat' => 22, 'fiber' => 5, 'sugar' => 11, 'sodium' => 1480,
                    'calcium' => 220, 'iron' => 6, 'potassium' => 820, 'zinc' => 7,
                    'confidenceScore' => 0.8,
                ],
            ],
            'pizza' => [
                'type' => MealType::Dinner,
                'description' => 'Pepperoni pizza with a soda',
                'indulgence' => 0.95,
                'ingredients' => [
                    ['name' => 'pizza dough', 'amount' => '3 slices'],
                    ['name' => 'pepperoni', 'amount' => '2 oz'],
                    ['name' => 'mozzarella', 'amount' => '2 oz'],
                    ['name' => 'marinara', 'amount' => '1/4 cup'],
                    ['name' => 'cola', 'amount' => '12 oz'],
                ],
                'nutrients' => [
                    'calories' => 1040, 'protein' => 38, 'carbohydrates' => 112, 'fat' => 44,
                    'saturatedFat' => 18, 'fiber' => 6, 'sugar' => 44, 'sodium' => 2080,
                    'calcium' => 420, 'iron' => 5, 'potassium' => 560, 'zinc' => 5,
                    'confidenceScore' => 0.81,
                ],
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Dto\GoalDefinition;
use App\Entity\AnalysisEvent;
use App\Entity\ApiToken;
use App\Entity\Favorite;
use App\Entity\GoalCheckin;
use App\Entity\HealthMetric;
use App\Entity\MealEntry;
use App\Entity\NutritionGoal;
use App\Entity\Supplement;
use App\Entity\User;
use App\Enum\AnalysisKind;
use App\Enum\GoalAggregation;
use App\Enum\GoalDirection;
use App\Enum\GoalMetric;
use App\Enum\HealthMetricType;
use App\Enum\MealType;
use App\Service\AccountDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Self-service account deletion, end-to-end against the real DB (in-process, not
 * Panther — same rationale as BetaGrandfatheringTest/FixSleepOutliersTest): the
 * grace-period request/cancel flow, and the "delete my data, keep my account" and
 * full-account wipes, including the two file-cleanup gaps fixed alongside this
 * feature (Supplement::imagePath, GoalCheckin::chartPath were previously orphaned).
 */
final class AccountDeletionTest extends WebTestCase
{
    use DbResetTrait;

    public function testRequestDeletionShowsBannerAndCancelRemovesIt(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'grace@test.local');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/settings/account');
        $form = $crawler->selectButton('Delete my account')->form();
        $client->submit($form);
        $client->followRedirect();

        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'grace@test.local']);
        self::assertNotNull($reloaded->getDeletionRequestedAt());

        // The app-wide banner appears on any authenticated page while pending.
        $crawler = $client->request('GET', '/');
        self::assertStringContainsString('permanently deleted on', $crawler->html());

        $form = $crawler->filter('form[action$="/cancel-deletion"]')->form();
        $client->submit($form);

        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'grace@test.local']);
        self::assertNull($reloaded->getDeletionRequestedAt());

        $crawler = $client->request('GET', '/');
        self::assertStringNotContainsString('permanently deleted on', $crawler->html());
    }

    public function testDeleteMyDataKeepsAccountAndTokensButWipesContentAndFiles(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'reset@test.local');
        $user->markOnboardingStep('meal', new \DateTimeImmutable());
        $em->flush();

        $mealFile = $this->seedMealWithFile($em, $user);
        $favoriteFile = $this->seedFavoriteWithFile($em, $user);
        $supplementFile = $this->seedSupplementWithFile($em, $user);
        $chartFile = $this->seedGoalWithCheckinChart($em, $user);
        $this->seedHealthMetric($em, $user);
        $this->seedApiToken($em, $user);
        $em->flush();

        self::assertFileExists($mealFile);
        self::assertFileExists($favoriteFile);
        self::assertFileExists($supplementFile);
        self::assertFileExists($chartFile);

        $service = static::getContainer()->get(AccountDeletionService::class);
        $service->purgeUserData($user);

        // Files gone — including the two previously-orphaned paths.
        self::assertFileDoesNotExist($mealFile);
        self::assertFileDoesNotExist($favoriteFile);
        self::assertFileDoesNotExist($supplementFile);
        self::assertFileDoesNotExist($chartFile);

        $em->clear();
        self::assertCount(0, $em->getRepository(MealEntry::class)->findAll());
        self::assertCount(0, $em->getRepository(Favorite::class)->findAll());
        self::assertCount(0, $em->getRepository(Supplement::class)->findAll());
        self::assertCount(0, $em->getRepository(NutritionGoal::class)->findAll());
        self::assertCount(0, $em->getRepository(GoalCheckin::class)->findAll());
        self::assertCount(0, $em->getRepository(HealthMetric::class)->findAll());

        // The account itself, its API tokens, and onboarding reset for a fresh start.
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'reset@test.local']);
        self::assertNotNull($reloaded);
        self::assertCount(1, $em->getRepository(ApiToken::class)->findBy(['user' => $reloaded]));
        self::assertNull($reloaded->getOnboardingMealAt());
        self::assertFalse($reloaded->isOnboardingDismissed());
    }

    public function testPurgeUserRemovesTheAccountItself(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'gone@test.local');
        $mealFile = $this->seedMealWithFile($em, $user);
        $em->flush();

        $service = static::getContainer()->get(AccountDeletionService::class);
        $service->purgeUser($user);

        self::assertFileDoesNotExist($mealFile);
        $em->clear();
        self::assertNull($em->getRepository(User::class)->findOneBy(['email' => 'gone@test.local']));
    }

    /**
     * The full purge of a power user — API token (minted by the CGM wizard), analysis
     * events, goals with charts. api_token.user_id has no ON DELETE, so a leftover
     * token used to block the `user` delete and roll the whole purge back.
     */
    public function testPurgeUserRemovesTokenHolderAndAllTheirContent(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'power@test.local');
        $bystander = $this->seedUser($em, 'bystander@test.local');

        $mealFile = $this->seedMealWithFile($em, $user);
        $favoriteFile = $this->seedFavoriteWithFile($em, $user);
        $supplementFile = $this->seedSupplementWithFile($em, $user);
        $chartFile = $this->seedGoalWithCheckinChart($em, $user);
        $this->seedHealthMetric($em, $user);
        $this->seedApiToken($em, $user);
        $this->seedApiToken($em, $bystander);
        $event = new AnalysisEvent();
        $event->setUser($user)->setKind(AnalysisKind::MealReanalysisPhoto)->setTokens(1000);
        $em->persist($event);
        $em->flush();
        foreach ([$mealFile, $favoriteFile, $supplementFile, $chartFile] as $file) {
            self::assertFileExists($file);
        }

        $service = static::getContainer()->get(AccountDeletionService::class);
        $service->purgeUser($user);

        self::assertFileDoesNotExist($mealFile);
        self::assertFileDoesNotExist($favoriteFile);
        self::assertFileDoesNotExist($supplementFile);
        self::assertFileDoesNotExist($chartFile);

        $em->clear();
        self::assertNull($em->getRepository(User::class)->findOneBy(['email' => 'power@test.local']));
        self::assertCount(0, $em->getRepository(MealEntry::class)->findAll());
        self::assertCount(0, $em->getRepository(NutritionGoal::class)->findAll());
        self::assertCount(0, $em->getRepository(GoalCheckin::class)->findAll());
        self::assertCount(0, $em->getRepository(AnalysisEvent::class)->findAll());

        // Only the purged user's token goes; another account's is untouched.
        $tokens = $em->getRepository(ApiToken::class)->findAll();
        self::assertCount(1, $tokens);
        self::assertSame('bystander@test.local', $tokens[0]->getUser()?->getEmail());
    }

    private function seedMealWithFile(EntityManagerInterface $em, User $user): string
    {
        $relativePath = 'uploads/meals/test-account-deletion-' . uniqid() . '.jpg';
        $file = static::getContainer()->getParameter('kernel.project_dir') . '/public/' . $relativePath;
        file_put_contents($file, 'fake-image');

        $meal = new MealEntry();
        $meal->setUser($user)
            ->setImagePath('/' . $relativePath)
            ->setMealType(MealType::Lunch)
            ->setLoggedAt(new \DateTimeImmutable())
            ->setIngredients([]);
        $em->persist($meal);

        return $file;
    }

    private function seedFavoriteWithFile(EntityManagerInterface $em, User $user): string
    {
        $relativePath = 'uploads/favorites/test-account-deletion-' . uniqid() . '.jpg';
        $file = static::getContainer()->getParameter('kernel.project_dir') . '/public/' . $relativePath;
        file_put_contents($file, 'fake-image');

        $favorite = new Favorite();
        $favorite->setUser($user)
            ->setName('Test favorite')
            ->setThumbnailPath('/' . $relativePath)
            ->setNutrients([])
            ->setIngredients([]);
        $em->persist($favorite);

        return $file;
    }

    private function seedSupplementWithFile(EntityManagerInterface $em, User $user): string
    {
        $relativePath = 'uploads/supplements/test-account-deletion-' . uniqid() . '.jpg';
        $file = static::getContainer()->getParameter('kernel.project_dir') . '/public/' . $relativePath;
        file_put_contents($file, 'fake-image');

        $supplement = new Supplement();
        $supplement->setUser($user)
            ->setName('Test supplement')
            ->setImagePath('/' . $relativePath)
            ->setServingsPerDay(1.0)
            ->setActive(true)
            ->setNutrients([]);
        $em->persist($supplement);

        return $file;
    }

    /** Seeds a goal + one check-in with a chart image — the previously-orphaned path. */
    private function seedGoalWithCheckinChart(EntityManagerInterface $em, User $user): string
    {
        $relativePath = 'uploads/goal-charts/test-account-deletion-' . uniqid() . '.png';
        $file = static::getContainer()->getParameter('kernel.project_dir') . '/public/' . $relativePath;
        file_put_contents($file, 'fake-chart');

        $definition = new GoalDefinition(
            GoalMetric::Fiber,
            GoalDirection::AtLeast,
            GoalAggregation::DailyAverage,
            25.0,
            7,
        );
        $goal = new NutritionGoal();
        $goal->setUser($user)
            ->setName('Eat more fiber')
            ->setDefinition($definition)
            ->setEvaluationPromptTemplate('Evaluate the goal.');
        $em->persist($goal);

        $checkin = new GoalCheckin();
        $checkin->setGoal($goal)->setChartPath('/' . $relativePath);
        $em->persist($checkin);

        return $file;
    }

    private function seedHealthMetric(EntityManagerInterface $em, User $user): void
    {
        $metric = new HealthMetric();
        $metric->setUser($user)
            ->setMetricType(HealthMetricType::Weight)
            ->setValue(70.0)
            ->setRecordedAt(new \DateTimeImmutable())
            ->setSource('test-seed');
        $em->persist($metric);
    }

    private function seedApiToken(EntityManagerInterface $em, User $user): ApiToken
    {
        $token = new ApiToken();
        $token->setUser($user)
            ->setName('Test token')
            ->setTokenHash(hash('sha256', 'test-token-' . uniqid()));
        $em->persist($token);

        return $token;
    }

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Seeded User');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}

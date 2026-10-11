<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Dto\GoalDefinition;
use App\Entity\Favorite;
use App\Entity\GoalCheckin;
use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\NutritionGoal;
use App\Entity\Supplement;
use App\Entity\User;
use App\Enum\GoalAggregation;
use App\Enum\GoalDirection;
use App\Enum\GoalMetric;
use App\Enum\MealType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Server-side bounds the forms only enforced client-side, and a per-goal file
 * cleanup the account purge already did. In-process `WebTestCase` against the
 * real DB; each POST carries the CSRF token its page renders.
 */
final class InputBoundsAndCleanupTest extends WebTestCase
{
    use DbResetTrait;

    public function testNonFiniteServingsPerDayIsStoredAsOne(): void
    {
        [$client, $em, $user] = $this->signedIn();
        $supplement = new Supplement();
        $supplement->setUser($user)->setName('Vitamin D')->setServingsPerDay(2.0)->setActive(true)->setNutrients([]);
        $em->persist($supplement);
        $em->flush();
        $id = $supplement->getId();

        $crawler = $client->request('GET', '/supplements');
        $token = (string) $crawler
            ->filter(sprintf('form[action="/supplements/%d/update"] input[name="_token"]', $id))
            ->attr('value');
        $client->request('POST', sprintf('/supplements/%d/update', $id), [
            '_token' => $token,
            'servings_per_day' => '1e999', // casts to INF, which max(0.0, …) lets through
        ]);

        self::assertResponseRedirects('/supplements');
        $em->clear();
        self::assertSame(1.0, $em->getRepository(Supplement::class)->find($id)?->getServingsPerDay());
    }

    public function testOverlongFavoriteNameIsTruncatedNotA500(): void
    {
        [$client, $em, $user] = $this->signedIn();
        $meal = new MealEntry();
        $meal->setUser($user)->setMealType(MealType::Lunch)->setLoggedAt(new \DateTimeImmutable('-1 hour'));
        $meal->setNutritionData(NutritionData::fromArray(['calories' => 500, 'confidenceScore' => 0.9]));
        $em->persist($meal);
        $em->flush();
        $id = $meal->getId();

        $crawler = $client->request('GET', sprintf('/meal/%d', $id));
        $token = (string) $crawler->filter('form[action="/meal/favorite/new"] input[name="_token"]')->attr('value');
        $client->request('POST', '/meal/favorite/new', [
            '_token' => $token,
            'meal_id' => (string) $id,
            'name' => str_repeat('Salad ', 60), // 360 chars
        ]);

        self::assertResponseRedirects(sprintf('/meal/%d', $id));
        $em->clear();
        $favorite = $em->getRepository(Favorite::class)->findOneBy([]);
        self::assertNotNull($favorite);
        self::assertSame(200, mb_strlen($favorite->getName()));
    }

    public function testDeletingAGoalRemovesItsCheckinCharts(): void
    {
        [$client, $em, $user] = $this->signedIn();
        $chartsDir = (string) static::getContainer()->getParameter('goal_charts_dir');
        if (!is_dir($chartsDir)) {
            mkdir($chartsDir, 0775, true);
        }
        $stem = 'goal-test-' . bin2hex(random_bytes(8));
        $chartFile = $chartsDir . '/' . $stem . '.png';
        file_put_contents($chartFile, 'fake-chart');

        $goal = new NutritionGoal();
        $goal->setUser($user)
            ->setName('Eat more fiber')
            ->setDefinition(new GoalDefinition(
                GoalMetric::Fiber,
                GoalDirection::AtLeast,
                GoalAggregation::DailyAverage,
                25.0,
                7,
            ))
            ->setEvaluationPromptTemplate('Evaluate the goal.');
        $em->persist($goal);
        $checkin = new GoalCheckin();
        $checkin->setGoal($goal)->setChartPath('/uploads/goal-charts/' . $stem . '.png');
        $em->persist($checkin);
        $em->flush();
        $id = $goal->getId();

        $crawler = $client->request('GET', sprintf('/goals/%d', $id));
        $token = (string) $crawler
            ->filter(sprintf('form[action="/goals/%d/delete"] input[name="_token"]', $id))
            ->attr('value');
        $client->request('POST', sprintf('/goals/%d/delete', $id), ['_token' => $token]);

        self::assertResponseRedirects('/goals');
        self::assertFileDoesNotExist($chartFile);
    }

    /** @return array{KernelBrowser, EntityManagerInterface, User} */
    private function signedIn(): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('bounds@test.local');
        $user->setDisplayName('Bounds');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setOnboardingDismissed(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return [$client, $em, $user];
    }
}

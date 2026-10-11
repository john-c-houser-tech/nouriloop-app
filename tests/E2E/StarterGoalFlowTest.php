<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalDirection;
use App\Enum\GoalFrequency;
use App\Enum\GoalMetric;
use App\Service\OnboardingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * One-click starter goals (in-process, real DB): the quick-start strip renders on
 * the chat page for users with no goals (and disappears once one exists), a
 * starter button creates a real weekly goal with the DV target and marks the
 * onboarding goal step, the onboarding overlay's goal step carries the same
 * buttons, and junk templates are rejected.
 */
final class StarterGoalFlowTest extends WebTestCase
{
    use DbResetTrait;

    public function testStarterButtonCreatesGoalAndMarksOnboarding(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'starter@test.local');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/goals/new');
        self::assertResponseIsSuccessful();
        // No goals yet → the quick-start strip renders.
        self::assertSelectorTextContains('body', 'Quick start');

        $form = $crawler->filter('form[action$="/goals/starter"]')
            ->reduce(fn ($node) => $node->filter('input[name="template"][value="fiber"]')->count() === 1)
            ->first()
            ->form();
        $client->submit($form);

        $em->clear();
        $goal = $em->getRepository(NutritionGoal::class)->findOneBy([]);
        self::assertNotNull($goal, 'one tap creates the goal');
        self::assertResponseRedirects('/goals/' . $goal->getId());

        $def = $goal->getDefinition();
        self::assertSame(GoalMetric::Fiber, $def->metric);
        self::assertSame(GoalDirection::AtLeast, $def->direction);
        self::assertSame(28.0, $def->targetValue, 'no history → the fiber DV');
        self::assertSame(7, $def->evaluationWindowDays);
        self::assertSame(GoalFrequency::Weekly, $goal->getFrequency());
        self::assertSame('More fiber', $goal->getName());
        self::assertNotNull($goal->getNextRunAt());

        // Creating the goal advanced onboarding step 2 (via GoalFactory).
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'starter@test.local']);
        self::assertNotNull($reloaded->getOnboardingGoalAt());

        // With a goal in place the quick-start strip is gone.
        $crawler = $client->request('GET', '/goals/new');
        self::assertStringNotContainsString('Quick start', $crawler->filter('body')->text());
    }

    public function testOnboardingGoalStepCarriesTheStarterButtons(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'overlay@test.local');

        // Advance to the goal step so it's the active card.
        static::getContainer()->get(OnboardingService::class)->markStep($user, 'meal');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertCount(
            4,
            $crawler->filter('#onboarding-overlay form[action$="/goals/starter"]'),
            'all four starter buttons render in the overlay',
        );

        // Submitting one from the overlay works like anywhere else.
        $form = $crawler->filter('#onboarding-overlay form[action$="/goals/starter"]')
            ->reduce(fn ($node) => $node->filter('input[name="template"][value="sugar"]')->count() === 1)
            ->first()
            ->form();
        $client->submit($form);

        $em->clear();
        $goal = $em->getRepository(NutritionGoal::class)->findOneBy([]);
        self::assertNotNull($goal);
        self::assertSame(GoalMetric::Sugar, $goal->getDefinition()->metric);
        self::assertSame(GoalDirection::AtMost, $goal->getDefinition()->direction);
    }

    public function testJunkTemplateCreatesNothing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'junk@test.local');
        $client->loginUser($user);

        // Grab a real CSRF token from a rendered starter form, then post junk.
        $crawler = $client->request('GET', '/goals/new');
        $token = $crawler->filter('form[action$="/goals/starter"] input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/goals/starter', [
            '_token'   => $token,
            'template' => 'world-peace',
        ]);
        self::assertResponseRedirects('/goals/new');

        $em->clear();
        self::assertNull($em->getRepository(NutritionGoal::class)->findOneBy([]));
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
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Favorite;
use App\Entity\Supplement;
use App\Entity\User;
use App\Enum\SubscriptionTier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Phase-3 Predictive Help (Loop+): the feature gate, request validation, and an
 * end-to-end favorite-daily projection with Claude mocked (FakeAnthropicResponder).
 * In-process WebTestCase (no browser) against the real nutrition_test DB, so it
 * lives under tests/E2E to run in the DB-having e2e job alongside the other
 * functional tests.
 */
final class PredictiveInsightTest extends WebTestCase
{
    use DbResetTrait;

    public function testFreeUserIsRedirectedToPricing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'free@test.local'); // default Free tier

        $client->loginUser($user);
        $client->request('GET', '/insights/predict');

        self::assertResponseRedirects('/pricing');
    }

    public function testLoopPlusUserSeesTheForm(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedLoopPlusUser($em, 'plus@test.local');

        $client->loginUser($user);
        $client->request('GET', '/insights/predict');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Predict');
        self::assertSelectorExists('form[action="/insights/predict/run"]');
    }

    public function testFavoriteDailyProjectionRendersResults(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedLoopPlusUser($em, 'plus@test.local');
        $favorite = $this->seedFavorite($em, $user);

        $client->loginUser($user);
        $this->resetLimiter($user);
        $client->request('POST', '/insights/predict/run', [
            '_token' => $this->formToken($client),
            'type' => 'favorite_daily',
            'favorite_id' => (string) $favorite->getId(),
            'duration_weeks' => '2',
            'dimensions' => ['inflammation', 'nutrition'],
        ]);

        self::assertResponseIsSuccessful();
        // The computed before/after table is present...
        self::assertSelectorTextContains('table', 'Inflammation Score (avg)');
        self::assertSelectorTextContains('table', 'Protein');
        // ...and the mocked Claude narrative renders below it.
        self::assertSelectorTextContains('body', 'educational estimate');
    }

    public function testFreeTextProjectionFlagsQualitativeEstimate(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedLoopPlusUser($em, 'plus@test.local');

        $client->loginUser($user);
        $this->resetLimiter($user);
        $client->request('POST', '/insights/predict/run', [
            '_token' => $this->formToken($client),
            'type' => 'free_text',
            'free_text' => 'I cut my added sugar in half for two weeks',
            'duration_weeks' => '2',
            'dimensions' => ['inflammation', 'nutrition'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'qualitative AI estimate');
    }

    public function testMissingFavoriteIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedLoopPlusUser($em, 'plus@test.local');

        $client->loginUser($user);
        $this->resetLimiter($user);
        // favorite_daily with no favorite_id → validation error, no projection.
        $client->request('POST', '/insights/predict/run', [
            '_token' => $this->formToken($client),
            'type' => 'favorite_daily',
            'duration_weeks' => '2',
            'dimensions' => ['inflammation'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', 'valid favorite meal');
    }

    public function testForeignFavoriteIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $owner = $this->seedLoopPlusUser($em, 'owner@test.local');
        $attacker = $this->seedLoopPlusUser($em, 'attacker@test.local');
        $othersFavorite = $this->seedFavorite($em, $owner);

        $client->loginUser($attacker);
        $this->resetLimiter($attacker);
        $client->request('POST', '/insights/predict/run', [
            '_token' => $this->formToken($client),
            'type' => 'favorite_daily',
            'favorite_id' => (string) $othersFavorite->getId(),
            'duration_weeks' => '2',
            'dimensions' => ['inflammation'],
        ]);

        // Ownership check fails → rejected, never computes against another user's data.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', 'valid favorite meal');
    }

    public function testAddingAnAlreadyActiveSupplementIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedLoopPlusUser($em, 'plus@test.local');
        $active = $this->seedSupplement($em, $user, isActive: true);

        $client->loginUser($user);
        $this->resetLimiter($user);
        $client->request('POST', '/insights/predict/run', [
            '_token' => $this->formToken($client),
            'type' => 'supplement_add',
            'supplement_id' => (string) $active->getId(),
            'duration_weeks' => '2',
            'dimensions' => ['nutrition'],
        ]);

        // Adding one already active would double-count it against the baseline.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', 'already in your daily routine');
    }

    public function testRemovingAnInactiveSupplementIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedLoopPlusUser($em, 'plus@test.local');
        $inactive = $this->seedSupplement($em, $user, isActive: false);

        $client->loginUser($user);
        $this->resetLimiter($user);
        $client->request('POST', '/insights/predict/run', [
            '_token' => $this->formToken($client),
            'type' => 'supplement_remove',
            'supplement_id' => (string) $inactive->getId(),
            'duration_weeks' => '2',
            'dimensions' => ['nutrition'],
        ]);

        // Removing a supplement not in the active stack has no baseline to subtract.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', 'supplement you currently take');
    }

    /**
     * The predictive_insight limiter is backed by the shared cache pool, which
     * truncateAll() doesn't clear — so its 10/hour state would otherwise leak
     * across test runs for the fixed test-user identifiers and eventually 429.
     */
    private function resetLimiter(User $user): void
    {
        $factory = static::getContainer()->get('limiter.predictive_insight');
        $factory->create('predict-user-' . $user->getUserIdentifier())->reset();
    }

    /**
     * Fetch a valid CSRF token by loading the form (test env uses stateful CSRF,
     * so the token must come from the same client session that submits it).
     */
    private function formToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/insights/predict');

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
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
        // Verified so the unverified-email banner (its form/_token would be picked up
        // by naive crawler selectors) stays out of these flows.
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function seedLoopPlusUser(EntityManagerInterface $em, string $email): User
    {
        $user = $this->seedUser($em, $email);
        // A permanent comp grants Loop+ without any Stripe state.
        $user->setCompTier(SubscriptionTier::LoopPlus);
        $user->setCompExpiresAt(null);
        $em->flush();

        return $user;
    }

    private function seedFavorite(EntityManagerInterface $em, User $user): Favorite
    {
        $favorite = new Favorite();
        $favorite->setUser($user);
        $favorite->setName('Oatmeal with blueberries');
        $favorite->setNutrients([
            'calories' => 360.0,
            'protein' => 12.0,
            'carbohydrates' => 58.0,
            'fat' => 9.0,
            'fiber' => 10.0,
            'sugar' => 14.0,
            'sodium' => 120.0,
            'confidenceScore' => 0.85,
        ]);
        $favorite->setIngredients([
            ['name' => 'rolled oats', 'amount' => '80', 'unit' => 'g'],
            ['name' => 'blueberries', 'amount' => '100', 'unit' => 'g'],
            ['name' => 'walnuts', 'amount' => '20', 'unit' => 'g'],
        ]);

        $em->persist($favorite);
        $em->flush();

        return $favorite;
    }

    private function seedSupplement(EntityManagerInterface $em, User $user, bool $isActive): Supplement
    {
        $supplement = new Supplement();
        $supplement->setUser($user);
        $supplement->setName('Test Vitamin D');
        $supplement->setServingsPerDay(1.0);
        $supplement->setActive($isActive);
        $supplement->setNutrients(['vitaminD' => 25.0]);

        $em->persist($supplement);
        $em->flush();

        return $supplement;
    }
}

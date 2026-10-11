<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Favorite;
use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Enum\MealType;
use App\Tests\Http\FakeAnthropicResponder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Recipe photo import (in-process, real DB, mocked Claude): multi-photo upload
 * → one analysis call → confirm screen → per-serving division into a normal
 * Favorite, the zero-Claude-call round trip through logFromFavorite, the
 * shared Free-tier allowance (an import both respects AND consumes the meal
 * bucket), and the clarify/re-analyze round.
 */
final class RecipeImportTest extends WebTestCase
{
    use DbResetTrait;

    public function testImportDividesBatchNutritionIntoAPerServingFavorite(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'import@test.local'));

        // Entry point: the favorites page links to the scanner.
        $crawler = $client->request('GET', '/meal/favorites');
        self::assertGreaterThan(0, $crawler->filter('a[href="/meal/recipe/scan"]')->count());

        $this->submitScan($client, [$this->makeJpeg(60), $this->makeJpeg(160)]);
        $this->assertResponseRedirects('/meal/recipe/confirm');
        $crawler = $client->followRedirect();

        // The confirm screen shows what Claude read: title, yield, batch totals.
        $html = $crawler->html();
        self::assertSame("Grandma's Lentil Soup", $crawler->filter('input[name="title"]')->attr('value'));
        self::assertSame('8', $crawler->filter('#recipe-servings')->attr('value'));
        self::assertStringContainsString('dried green lentils', $html);
        self::assertStringContainsString('Is the stock in step 2 low-sodium or regular?', $html);
        self::assertStringContainsString('1,600', $html, 'batch calories shown');

        $this->submitConfirm($client, $crawler, [
            'title'    => 'Family lentil soup',
            'servings' => '8',
        ]);
        $this->assertResponseRedirects('/meal/favorites');

        $em->clear();
        $favorite = $em->getRepository(Favorite::class)->findOneBy([]);
        self::assertNotNull($favorite);
        self::assertSame('Family lentil soup', $favorite->getName());

        // 1600 kcal batch / 8 servings — the favorite stores ONE serving.
        self::assertEqualsWithDelta(200.0, (float) $favorite->getNutrients()['calories'], 0.01);
        self::assertEqualsWithDelta(12.0, (float) $favorite->getNutrients()['protein'], 0.01);
        self::assertEqualsWithDelta(0.78, (float) $favorite->getNutrients()['confidenceScore'], 0.01);

        // Numeric ingredient amounts divide too; "to taste" is left verbatim.
        $ingredients = $favorite->getIngredients();
        self::assertSame('0.25', $ingredients[0]['amount'], '2 cups / 8 servings');
        self::assertSame('to taste', $ingredients[2]['amount']);

        self::assertSame(8.0, $favorite->getServingsAtImport());
        self::assertStringContainsString('simmer 40 minutes', (string) $favorite->getInstructions());
        self::assertSame(1500, $favorite->getTokensPerAnalysis(), 'the analysis cost each reuse avoids');
        self::assertNotNull($favorite->getThumbnailPath(), 'first photo became the favorite thumbnail');
    }

    public function testImportedFavoriteLogsAtAnyPortionWithoutAClaudeCall(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'roundtrip@test.local');
        $client->loginUser($user);

        $this->submitScan($client, [$this->makeJpeg(90)]);
        $crawler = $client->followRedirect();
        $this->submitConfirm($client, $crawler, ['servings' => '8']);

        $em->clear();
        $favorite = $em->getRepository(Favorite::class)->findOneBy([]);
        $crawler = $client->request('GET', '/meal/favorites');
        $form = $crawler->filter('form[action="/meal/favorite/' . $favorite->getId() . '/log"]')->form([
            'portions'  => '2',
            'meal_type' => 'dinner',
        ]);
        $client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        // The kernel (and so the mock client's counter) is fresh per request:
        // 0 here means the favorite-log POST itself made no Claude call.
        $mock = static::getContainer()->get('test.anthropic_http_client');
        self::assertInstanceOf(MockHttpClient::class, $mock);
        self::assertSame(0, $mock->getRequestsCount(), 'logging from an imported favorite must not hit the API');

        $em->clear();
        $meal = $em->getRepository(MealEntry::class)->findOneBy([]);
        self::assertNotNull($meal);
        self::assertEqualsWithDelta(400.0, (float) $meal->getNutritionData()->getCalories(), 0.01, '2 servings');
    }

    public function testExhaustedFreeAllowanceBlocksTheImportBeforeAnyClaudeCall(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'blocked@test.local');
        for ($i = 0; $i < 10; $i++) {
            $this->seedMeal($em, $user, $i);
        }
        $client->loginUser($user);

        $this->submitScan($client, [$this->makeJpeg(120)]);
        $this->assertResponseRedirects('/pricing');

        $mock = static::getContainer()->get('test.anthropic_http_client');
        self::assertInstanceOf(MockHttpClient::class, $mock);
        self::assertSame(0, $mock->getRequestsCount(), 'the gate fires before the API is touched');
        self::assertNull($em->getRepository(Favorite::class)->findOneBy([]));
    }

    public function testAnImportConsumesTheSameMonthlyBucketAsMealPhotos(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'bucket@test.local');
        for ($i = 0; $i < 9; $i++) {
            $this->seedMeal($em, $user, $i);
        }
        $client->loginUser($user);

        // 9 meals logged: the 10th and last analysis goes to this import…
        $this->submitScan($client, [$this->makeJpeg(70)]);
        $crawler = $client->followRedirect();
        $this->submitConfirm($client, $crawler, ['servings' => '4']);
        $this->assertResponseRedirects('/meal/favorites');

        // …so the next import is out of allowance.
        $this->submitScan($client, [$this->makeJpeg(75)]);
        $this->assertResponseRedirects('/pricing');
    }

    public function testReanalyzeRoundSendsAnswersBackAndRerendersTheConfirm(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'refine@test.local'));

        $this->submitScan($client, [$this->makeJpeg(50)]);
        $crawler = $client->followRedirect();

        $this->submitConfirm($client, $crawler, [
            'action'       => 'reanalyze',
            'answers'      => ['Low-sodium stock'],
            'instructions' => 'MY EDITED STEPS: use the pressure cooker.',
        ]);
        $this->assertResponseRedirects('/meal/recipe/confirm');

        // Per-request counter (kernel reboots between requests): 1 here means
        // the re-analyze POST made exactly one Claude call.
        $mock = static::getContainer()->get('test.anthropic_http_client');
        self::assertInstanceOf(MockHttpClient::class, $mock);
        self::assertSame(1, $mock->getRequestsCount(), 'a refinement round is one more call');
        self::assertStringContainsString(
            "Q: Is the stock in step 2 low-sodium or regular?\nA: Low-sodium stock",
            (string) FakeAnthropicResponder::lastAnalysisPrompt(),
            'the answer reaches the AI with its question',
        );

        $crawler = $client->followRedirect();
        $this->assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('#recipe-servings')->count(), 'still on the confirm screen');
        self::assertStringContainsString(
            'MY EDITED STEPS',
            $crawler->filter('textarea[name="instructions"]')->text(),
            'the user\'s manual instruction edits survive a nutrition refinement',
        );

        // The updated estimate saves normally afterwards.
        $this->submitConfirm($client, $crawler, ['servings' => '8']);
        $this->assertResponseRedirects('/meal/favorites');
        $em->clear();
        self::assertNotNull($em->getRepository(Favorite::class)->findOneBy([]));
    }

    public function testAReanalysisSendsAnIngredientCorrectionOnlyWhenTheListChanged(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'corrections@test.local'));

        $this->submitScan($client, [$this->makeJpeg(55)]);
        $crawler = $client->followRedirect();

        // The table posted exactly as the AI read it: no correction.
        $this->submitConfirm($client, $crawler, ['action' => 'reanalyze']);
        self::assertStringNotContainsString(
            'corrected the ingredient list',
            (string) FakeAnthropicResponder::lastAnalysisPrompt(),
        );

        // One name changed: the corrected list goes to the AI.
        $crawler = $client->followRedirect();
        $this->submitConfirm($client, $crawler, ['action' => 'reanalyze', 'ing_name' => ['red lentils']]);
        self::assertStringContainsString(
            'corrected the ingredient list to: ',
            (string) FakeAnthropicResponder::lastAnalysisPrompt(),
        );
        self::assertStringContainsString('red lentils', (string) FakeAnthropicResponder::lastAnalysisPrompt());
    }

    public function testNonFiniteOrNonPositiveServingsAreRejectedServerSide(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'servings@test.local'));

        $this->submitScan($client, [$this->makeJpeg(30)]);
        $crawler = $client->followRedirect();

        // '1e309' floats to INF, which passes a naive > 0 check; '0' is the
        // plain non-positive case. Neither may reach the division/DB.
        foreach (['1e309', '0'] as $bad) {
            $this->submitConfirm($client, $crawler, ['servings' => $bad]);
            $this->assertResponseRedirects('/meal/recipe/confirm');
            $client->followRedirect();
        }

        $em->clear();
        self::assertNull($em->getRepository(Favorite::class)->findOneBy([]), 'nothing was saved');
    }

    public function testPhase2BadgeStepsCollapseAndDashboardEntryPoint(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'phase2@test.local');
        $client->loginUser($user);

        // One imported favorite…
        $this->submitScan($client, [$this->makeJpeg(85)]);
        $crawler = $client->followRedirect();
        $this->submitConfirm($client, $crawler, ['servings' => '8']);

        // …one ordinary favorite (no servingsAtImport, no instructions)…
        // Kernel reboots between requests detach the seeded $user — re-attach
        // via the current container's EM before persisting a relation to it.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $user->getId());
        $plain = new Favorite();
        $plain->setUser($user);
        $plain->setName('Plain oatmeal');
        // Full toArray() shape — real favorites always store the complete map.
        $plain->setNutrients(NutritionData::fromArray(['calories' => 300, 'confidenceScore' => 0.9])->toArray());
        $plain->setIngredients([]);
        $em->persist($plain);

        // …and a "legacy" import predating batch_ingredients: only the
        // per-serving division survives, so display must reconstruct × servings.
        $legacy = new Favorite();
        $legacy->setUser($user);
        $legacy->setName('Legacy soup');
        $legacy->setNutrients(NutritionData::fromArray(['calories' => 150, 'confidenceScore' => 0.8])->toArray());
        $legacy->setIngredients([['name' => 'red lentils', 'amount' => '0.5', 'unit' => 'cups']]);
        $legacy->setServingsAtImport(4.0);
        $legacy->setInstructions('Legacy steps.');
        $em->persist($legacy);
        $em->flush();

        $imported = $em->getRepository(Favorite::class)->findOneBy(['name' => "Grandma's Lentil Soup"]);
        self::assertNotNull($imported);

        $crawler = $client->request('GET', '/meal/favorites');
        $this->assertResponseIsSuccessful();

        // The badge marks the two imports, never the plain favorite.
        self::assertCount(2, $crawler->filter('span.badge:contains("From a recipe")'));
        self::assertCount(2, $crawler->filter('button[data-bs-target^="#steps-"]'));

        // The fresh import's reference shows the WHOLE-BATCH quantities as
        // confirmed (2 cups, not the stored per-serving 0.25) plus the steps.
        $reference = $crawler->filter('#steps-' . $imported->getId())->text();
        self::assertStringContainsString('Ingredients — makes 8 servings', $reference);
        self::assertStringContainsString('2 cups dried green lentils', $reference);
        self::assertStringContainsString('to taste salt', $reference);
        self::assertStringContainsString('simmer 40 minutes', $reference);

        // The legacy import reconstructs batch = per-serving × servings.
        $legacyRef = $crawler->filter('#steps-' . $legacy->getId())->text();
        self::assertStringContainsString('Ingredients — makes 4 servings', $legacyRef);
        self::assertStringContainsString('2 cups red lentils', $legacyRef);
        self::assertStringContainsString('Legacy steps.', $legacyRef);

        // The dashboard header links to the scanner (Phase 2 entry point).
        $crawler = $client->request('GET', '/');
        self::assertGreaterThan(0, $crawler->filter('a[href="/meal/recipe/scan"]')->count());
    }

    public function testConfirmWithoutASessionRedirectsToTheScanner(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'nosession@test.local'));

        $client->request('GET', '/meal/recipe/confirm');
        $this->assertResponseRedirects('/meal/recipe/scan');
    }

    /**
     * Uploads photos to the scanner the way the browser would (CSRF from the
     * rendered form; the multiple-file field needs a manual request).
     *
     * @param list<string> $files
     */
    private function submitScan(KernelBrowser $client, array $files): Crawler
    {
        $crawler = $client->request('GET', '/meal/recipe/scan');
        $token = (string) $crawler->filter('input[name="recipe_photo_form[_token]"]')->attr('value');

        return $client->request(
            'POST',
            '/meal/recipe/scan',
            ['recipe_photo_form' => ['_token' => $token]],
            ['recipe_photo_form' => [
                'images' => array_map(
                    static fn(string $p): UploadedFile => new UploadedFile($p, basename($p), 'image/jpeg', null, true),
                    $files,
                ),
            ]],
        );
    }

    /**
     * Posts the confirm form (save by default) with the rendered CSRF token
     * and the AI-prefilled ingredient rows, overridden by $fields. Arrays for
     * the ingredient inputs need a manual request, not the crawler Form API.
     *
     * @param array<string, string> $fields
     */
    private function submitConfirm(KernelBrowser $client, Crawler $crawler, array $fields): Crawler
    {
        // Scoped to the confirm form — base.html.twig renders other _token
        // fields (onboarding overlay etc.) on authenticated pages.
        $token = (string) $crawler
            ->filter('form[action="/meal/recipe/confirm"] input[name="_token"]')
            ->attr('value');
        $rows = $crawler->filter('#ingredient-rows .ingredient-row');

        return $client->request('POST', '/meal/recipe/confirm', array_merge([
            '_token'       => $token,
            'action'       => 'save',
            'title'        => (string) $crawler->filter('input[name="title"]')->attr('value'),
            'servings'     => (string) $crawler->filter('#recipe-servings')->attr('value'),
            'instructions' => 'Saute the onion in oil. Add lentils and stock; simmer 40 minutes.',
            'ing_name'     => $rows->each(
                static fn(Crawler $row): string => (string) $row->filter('input[name="ing_name[]"]')->attr('value'),
            ),
            'ing_amount'   => $rows->each(
                static fn(Crawler $row): string => (string) $row->filter('input[name="ing_amount[]"]')->attr('value'),
            ),
            'ing_unit'     => $rows->each(
                static fn(Crawler $row): string => (string) $row->filter('input[name="ing_unit[]"]')->attr('value'),
            ),
        ], $fields));
    }

    private function makeJpeg(int $shade): string
    {
        $path = tempnam(sys_get_temp_dir(), 'recipe') . '.jpg';
        $im = imagecreatetruecolor(24, 24);
        imagefilledrectangle($im, 0, 0, 23, 23, (int) imagecolorallocate($im, $shade, 90, 60));
        imagejpeg($im, $path);

        return $path;
    }

    /**
     * Seeds a meal $offsetMinutes past noon on the 1st of the user's current
     * local month — always inside the month window countForUserThisMonth
     * checks, no matter when the suite runs (a relative "-N hours" would slip
     * into last month near a month boundary).
     */
    private function seedMeal(EntityManagerInterface $em, User $user, int $offsetMinutes): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone()));
        $loggedAt = $now
            ->setDate((int) $now->format('Y'), (int) $now->format('n'), 1)
            ->setTime(12, 0)
            ->modify(sprintf('+%d minutes', $offsetMinutes));

        $entry = new MealEntry();
        $entry->setUser($user);
        $entry->setMealType(MealType::Lunch);
        $entry->setLoggedAt($loggedAt);
        $entry->setNutritionData(NutritionData::fromArray(['calories' => 500, 'confidenceScore' => 0.9]));

        $em->persist($entry);
        $em->flush();
    }

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Recipe Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        // Keep the onboarding overlay (and its forms) off these pages.
        $user->setOnboardingDismissed(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}

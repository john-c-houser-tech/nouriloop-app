<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\AnalysisEvent;
use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Enum\AnalysisKind;
use App\Enum\MealType;
use App\Service\AnalysisAllowance;
use App\Service\UserTime;
use App\Tests\Http\FakeAnthropicResponder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Meal history Phase 3 (in-process, real DB): re-analysing an already-logged
 * meal from a new photo or an edited description. The two properties the plan
 * calls for are what this pins hardest — nothing is applied without an explicit
 * diff-then-confirm (§4), and the call draws on the monthly allowance even
 * though it creates no meal row.
 *
 * "Claude" is FakeAnthropicResponder throughout, so the proposed estimate is
 * always the canned meal JSON (520 kcal / 46 g protein / …).
 */
final class MealReanalyzeTest extends WebTestCase
{
    use DbResetTrait;

    /** The canned re-analysis result, so expectations read as intent. */
    private const PROPOSED_CALORIES = 520.0;
    private const PROPOSED_PROTEIN = 46.0;

    /** What seedMeal() stores, deliberately different from the canned result. */
    private const STORED_CALORIES = 430.0;

    protected function setUp(): void
    {
        FakeAnthropicResponder::resetFailureTrigger();
    }

    public function testDescriptionReanalysisIsProposedThenAppliedOnConfirm(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'describe-reanalyze@test.local');
        $meal = $this->seedMeal($em, $user);
        $client->loginUser($user);

        // The form prefills the AI's own description when the meal has no notes
        // — the text the user is being asked to correct.
        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/reanalyze');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Lentil soup with bread',
            $crawler->filter('textarea[name="meal_reanalyze_form[description]"]')->text(),
        );

        $client->submit($crawler->selectButton('Re-analyze this meal')->form([
            'meal_reanalyze_form[description]' => 'Lentil soup with two slices of sourdough and olive oil',
        ]));
        $this->assertResponseRedirects('/meal/' . $meal->getId() . '/reanalyze/review');

        // Nothing is written yet — the whole point of the review step.
        $em->clear();
        $pending = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertSame(self::STORED_CALORIES, $pending->getNutritionData()->getCalories());
        self::assertNull($pending->getReanalyzedAt());

        $review = $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $html = $review->html();
        self::assertStringContainsString('Review the new estimate', $html);
        self::assertStringContainsString('430', $html, 'the current value is shown');
        self::assertStringContainsString('520', $html, 'the proposed value is shown');

        $client->submit($review->selectButton('Apply this estimate')->form());
        $this->assertResponseRedirects('/meal/' . $meal->getId());

        $em->clear();
        $fresh = $em->getRepository(MealEntry::class)->find($meal->getId());
        $n = $fresh->getNutritionData();
        self::assertSame(self::PROPOSED_CALORIES, $n->getCalories());
        self::assertSame(self::PROPOSED_PROTEIN, $n->getProtein());
        self::assertSame(0.86, $n->getConfidenceScore(), 'the new run\'s confidence replaces the old');
        self::assertSame(
            'Grilled chicken breast with white rice and broccoli',
            $fresh->getAiDescription(),
            'the AI description is replaced too',
        );
        self::assertSame(
            'Lentil soup with two slices of sourdough and olive oil',
            $fresh->getNotes(),
            'the submitted description persists as the notes, so the next re-analysis starts from it',
        );
        self::assertNotNull($fresh->getReanalyzedAt(), 'AI provenance stamped');
        self::assertNotNull($fresh->getEditedAt(), 'the edit trust marker is stamped too');

        // Ingredients come wholesale from the new estimate.
        self::assertSame('grilled chicken breast', $fresh->getIngredients()[0]['name']);

        // Live-read check: nothing caches meal aggregates, so the dashboard is
        // already showing the new number.
        self::assertStringContainsString('520', $client->request('GET', '/')->html());

        // A description-only run is ledgered as such.
        $events = $em->getRepository(AnalysisEvent::class)->findAll();
        self::assertCount(1, $events);
        self::assertSame(AnalysisKind::MealReanalysisDescription, $events[0]->getKind());
        self::assertSame(1500, $events[0]->getTokens(), 'the call cost is recorded');
    }

    public function testManualCorrectionsAreNotOverwrittenWithoutASecondConfirmation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'protect-manual@test.local');
        $meal = $this->seedMeal($em, $user);
        // The plan's §4 case: the user typed these numbers themselves.
        $meal->setNutritionEditedAt(new \DateTimeImmutable('-2 days'));
        $meal->getNutritionData()->setCalories(455.0);
        $em->flush();
        $client->loginUser($user);

        // Warned before the call is even spent.
        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/reanalyze');
        self::assertStringContainsString('corrected this meal\'s numbers by hand', $crawler->html());

        $client->submit($crawler->selectButton('Re-analyze this meal')->form([
            'meal_reanalyze_form[description]' => 'Lentil soup, larger bowl',
        ]));
        $review = $client->followRedirect();
        self::assertStringContainsString('replace numbers you corrected yourself', $review->html());

        // Apply WITHOUT ticking the box: refused, and the corrections survive.
        $client->submit($review->selectButton('Apply this estimate')->form());
        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'Please confirm replacing your manual corrections first.',
            $client->getCrawler()->html(),
        );

        $em->clear();
        $unchanged = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertSame(455.0, $unchanged->getNutritionData()->getCalories());
        self::assertNotNull($unchanged->getNutritionEditedAt());
        self::assertNull($unchanged->getReanalyzedAt());

        // Ticked: the estimate takes over, and the provenance flips — these are
        // an AI estimate again, so the confidence bar describes them once more.
        $form = $client->getCrawler()->selectButton('Apply this estimate')->form();
        $form['confirm_overwrite']->tick();
        $client->submit($form);
        $this->assertResponseRedirects('/meal/' . $meal->getId());

        $em->clear();
        $applied = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertSame(self::PROPOSED_CALORIES, $applied->getNutritionData()->getCalories());
        self::assertNull($applied->getNutritionEditedAt(), 'manual provenance no longer describes the numbers');
        self::assertNotNull($applied->getReanalyzedAt());

        $showHtml = $client->followRedirect()->html();
        self::assertStringContainsString('AI re-analyzed', $showHtml);
        self::assertStringNotContainsString('Edited by you', $showHtml);
    }

    public function testAManualNutrientEditAfterwardsClearsTheReanalysisStamp(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // The two provenance stamps are mutually exclusive answers to "where did
        // these numbers come from", so they must never both be set. Applying a
        // re-analysis clears nutritionEditedAt; this pins the other direction,
        // which the Phase-2 edit path originally missed (found live-testing).
        $user = $this->seedUser($em, 'provenance-both@test.local');
        $meal = $this->seedMeal($em, $user);
        $client->loginUser($user);

        $review = $this->runReanalysis($client, $meal, 'Lentil soup, re-read');
        $client->submit($review->selectButton('Apply this estimate')->form());
        $client->followRedirect();

        $em->clear();
        $reanalyzed = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertNotNull($reanalyzed->getReanalyzedAt());
        self::assertNull($reanalyzed->getNutritionEditedAt());

        // Now correct a number by hand.
        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/edit');
        $client->submit($crawler->selectButton('Save Changes')->form([
            'meal_edit_form[calories]' => '505',
        ]));
        $this->assertResponseRedirects('/meal/' . $meal->getId());

        $em->clear();
        $corrected = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertSame(505.0, $corrected->getNutritionData()->getCalories());
        self::assertNotNull($corrected->getNutritionEditedAt(), 'the manual stamp lands');
        self::assertNull(
            $corrected->getReanalyzedAt(),
            'the meal must stop claiming these are a fresh AI estimate',
        );

        $html = $client->followRedirect()->html();
        self::assertStringContainsString('Edited by you', $html);
        self::assertStringNotContainsString('AI re-analyzed', $html);
    }

    public function testDiscardLeavesTheMealUntouchedButStillCountsTheCall(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'discard@test.local');
        $meal = $this->seedMeal($em, $user);
        $client->loginUser($user);

        $review = $this->runReanalysis($client, $meal, 'Lentil soup, smaller portion');
        $client->submit($review->selectButton('Discard')->form());
        $this->assertResponseRedirects('/meal/' . $meal->getId());

        $em->clear();
        $fresh = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertSame(self::STORED_CALORIES, $fresh->getNutritionData()->getCalories());
        self::assertSame('Lentil soup with bread', $fresh->getAiDescription());
        self::assertNull($fresh->getReanalyzedAt());
        self::assertNull($fresh->getEditedAt(), 'discarding is not an edit');

        // The Claude call happened, so the slot is spent either way — that's
        // what a call-time ledger means.
        self::assertCount(1, $em->getRepository(AnalysisEvent::class)->findAll());

        // The proposal is gone: the review page has nothing to show any more.
        $client->request('GET', '/meal/' . $meal->getId() . '/reanalyze/review');
        $this->assertResponseRedirects('/meal/' . $meal->getId() . '/reanalyze');
    }

    public function testANewPhotoBecomesTheMealPhotoOnlyWhenReplaceIsTicked(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'photo-reanalyze@test.local');
        $meal = $this->seedMeal($em, $user);
        $original = $this->attachPhoto($em, $meal);
        $client->loginUser($user);

        // Round 1 — no replace tick: the upload is an analysis aid only, so the
        // meal keeps its photo and the aid is deleted on apply.
        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/reanalyze');
        $form = $crawler->selectButton('Re-analyze this meal')->form();
        $form['meal_reanalyze_form[image]'][0]->upload($this->makeJpeg());
        $client->submit($form);
        $review = $client->followRedirect();
        self::assertStringContainsString('the meal keeps its current photo', $review->html());

        $aid = $this->parkedPhotoPath($review);
        self::assertFileExists($aid);
        $client->submit($review->selectButton('Apply this estimate')->form());
        $client->followRedirect();

        $em->clear();
        $kept = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertSame('uploads/meals/' . basename($original), $kept->getImagePath());
        self::assertFileExists($original, 'the meal photo is untouched');
        self::assertFileDoesNotExist($aid, 'an analysis aid is not kept');

        // Round 2 — replace ticked: the upload becomes the meal photo and the
        // old file is removed from disk.
        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/reanalyze');
        $form = $crawler->selectButton('Re-analyze this meal')->form();
        $form['meal_reanalyze_form[image]'][0]->upload($this->makeJpeg());
        $form['meal_reanalyze_form[replace_photo]']->tick();
        $client->submit($form);
        $review = $client->followRedirect();
        self::assertStringContainsString('becomes the meal\'s photo', $review->html());

        $replacement = $this->parkedPhotoPath($review);
        $client->submit($review->selectButton('Apply this estimate')->form());
        $client->followRedirect();

        $em->clear();
        $swapped = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertSame('uploads/meals/' . basename($replacement), $swapped->getImagePath());
        self::assertFileExists($replacement);
        self::assertFileDoesNotExist($original, 'the replaced photo is cleaned up');

        // Both runs are ledgered as photo re-analyses.
        $events = $em->getRepository(AnalysisEvent::class)->findAll();
        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertSame(AnalysisKind::MealReanalysisPhoto, $event->getKind());
        }

        @unlink($replacement);
    }

    public function testReanalysisDrawsOnTheMonthlyAllowanceAndIsRefusedWhenExhausted(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // The plan requires these calls to be "counted against the existing
        // monthly allowance". A re-analysis creates no meal row, so a derived
        // count can't see it — the AnalysisEvent ledger is what makes this true,
        // and this case is the reason it exists.
        $user = $this->seedUser($em, 'allowance-reanalyze@test.local');
        self::assertNull($user->getCompTier(), 'must be a plain Free user for the allowance to apply');
        $meal = $this->seedMeal($em, $user);
        $allowance = static::getContainer()->get(AnalysisAllowance::class);

        // One meal logged this month, so one slot is already spent.
        self::assertSame(AnalysisAllowance::FREE_MONTHLY_LIMIT - 1, $allowance->remaining($user));
        $client->loginUser($user);

        $review = $this->runReanalysis($client, $meal, 'Lentil soup, second look');
        self::assertSame(
            AnalysisAllowance::FREE_MONTHLY_LIMIT - 2,
            $allowance->remaining($user),
            'the re-analysis spends a slot as soon as the call succeeds, before it is applied',
        );

        // Applying is free — it's a database write, not another call.
        $client->submit($review->selectButton('Apply this estimate')->form());
        self::assertSame(AnalysisAllowance::FREE_MONTHLY_LIMIT - 2, $allowance->remaining($user));

        // Burn the rest of the month's allowance with ledger rows, then the
        // form's own gate should turn the user away instead of calling Claude.
        // ⚠️ Re-fetch the services AND a MANAGED user: KernelBrowser reboots the
        // kernel between requests, so the container held above carries a stale
        // EntityManager that would refuse to persist this detached User.
        $liveEm = static::getContainer()->get(EntityManagerInterface::class);
        $liveAllowance = static::getContainer()->get(AnalysisAllowance::class);
        $now = static::getContainer()->get(UserTime::class);
        $managed = $liveEm->getRepository(User::class)->find($user->getId());
        while ($liveAllowance->remaining($managed) > 0) {
            $liveEm->persist((new AnalysisEvent())
                ->setUser($managed)
                ->setKind(AnalysisKind::MealReanalysisDescription)
                ->setOccurredAt($now->now($managed))
                ->setTokens(0));
            $liveEm->flush();
        }
        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/reanalyze');
        $client->submit($crawler->selectButton('Re-analyze this meal')->form([
            'meal_reanalyze_form[description]' => 'One more look',
        ]));
        $this->assertResponseRedirects('/pricing');
    }

    public function testDeletingTheMealDoesNotRefundAReanalysisSlot(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // The deliberate asymmetry with the Phase-2 posture: logging a meal and
        // deleting it refunds the slot (a derived count), but a re-analysis is
        // ledgered at call time, so deleting the meal it was for cannot undo it.
        $user = $this->seedUser($em, 'no-refund@test.local');
        $meal = $this->seedMeal($em, $user);
        $client->loginUser($user);
        $allowance = static::getContainer()->get(AnalysisAllowance::class);

        $review = $this->runReanalysis($client, $meal, 'Lentil soup again');
        $client->submit($review->selectButton('Apply this estimate')->form());
        $spent = $allowance->remaining($user);

        $show = $client->followRedirect();
        $client->submit($show->filter('form[action$="/delete"]')->form());
        $this->assertResponseRedirects('/');

        // The meal's own slot comes back; the re-analysis's does not.
        self::assertSame($spent + 1, $allowance->remaining($user));

        $em->clear();
        $events = $em->getRepository(AnalysisEvent::class)->findAll();
        self::assertCount(1, $events, 'the ledger row outlives the meal');
        self::assertNull($events[0]->getMealEntry(), 'its meal link is nulled, not cascaded');
    }

    public function testAFailedAnalysisIsNotChargedAndParksNothing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'failed-reanalyze@test.local');
        $meal = $this->seedMeal($em, $user);
        $client->loginUser($user);
        $allowance = static::getContainer()->get(AnalysisAllowance::class);
        $before = $allowance->remaining($user);

        // The responder fails on this marker in the prompt — the description
        // reaches the prompt, so it's the trigger channel here.
        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/reanalyze');
        $client->submit($crawler->selectButton('Re-analyze this meal')->form([
            'meal_reanalyze_form[description]' => FakeAnthropicResponder::FAIL_ALWAYS,
        ]));
        $this->assertResponseRedirects('/meal/' . $meal->getId() . '/reanalyze');

        self::assertSame($before, $allowance->remaining($user), 'a failed call is not charged');
        self::assertCount(0, $em->getRepository(AnalysisEvent::class)->findAll());
        self::assertStringContainsString("couldn't re-analyze", $client->followRedirect()->html());
    }

    public function testSubmittingNothingToAnalyzeIsRefusedBeforeAnyCall(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'nothing@test.local');
        // No photo and no notes/description: there is nothing to estimate from.
        $meal = $this->seedMeal($em, $user, withDescription: false);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/reanalyze');
        $client->submit($crawler->selectButton('Re-analyze this meal')->form([
            'meal_reanalyze_form[description]' => '   ',
        ]));
        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString("nothing to re-estimate from", $client->getCrawler()->html());
        self::assertCount(0, $em->getRepository(AnalysisEvent::class)->findAll());
    }

    public function testAProposalCannotBeAppliedToADifferentMeal(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'cross-meal@test.local');
        $mealA = $this->seedMeal($em, $user);
        $mealB = $this->seedMeal($em, $user);
        $client->loginUser($user);

        $this->runReanalysis($client, $mealA, 'Soup A, re-read');

        // The parked proposal is keyed to meal A; meal B must not be able to
        // consume it.
        $client->request('GET', '/meal/' . $mealB->getId() . '/reanalyze/review');
        $this->assertResponseRedirects('/meal/' . $mealB->getId() . '/reanalyze');

        $em->clear();
        self::assertSame(
            self::STORED_CALORIES,
            $em->getRepository(MealEntry::class)->find($mealB->getId())->getNutritionData()->getCalories(),
        );
    }

    public function testAnotherUsersMealIs404OnBothSteps(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $owner = $this->seedUser($em, 'owner-reanalyze@test.local');
        $meal = $this->seedMeal($em, $owner);
        $client->loginUser($this->seedUser($em, 'intruder-reanalyze@test.local'));

        $client->request('GET', '/meal/' . $meal->getId() . '/reanalyze');
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/meal/' . $meal->getId() . '/reanalyze/review');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAMealWithNoNutritionRowGainsOneFromTheReanalysis(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'no-nutrition@test.local');
        $meal = $this->seedMeal($em, $user, withNutrition: false);
        $client->loginUser($user);

        $review = $this->runReanalysis($client, $meal, 'Lentil soup with bread, first estimate');
        // Every row is an addition, so the diff says so rather than showing
        // dashes against dashes.
        self::assertStringContainsString('newly estimated', $review->html());

        $client->submit($review->selectButton('Apply this estimate')->form());
        $this->assertResponseRedirects('/meal/' . $meal->getId());

        $em->clear();
        $fresh = $em->getRepository(MealEntry::class)->find($meal->getId());
        self::assertNotNull($fresh->getNutritionData());
        self::assertSame(self::PROPOSED_CALORIES, $fresh->getNutritionData()->getCalories());
    }

    public function testAParkedPhotoSweptBeforeApplyDoesNotDestroyTheMealsOwnPhoto(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // ⚠️ The parked upload is unreferenced by any meal until it's applied,
        // which is exactly what app:cleanup-meal-uploads deletes — and a session
        // can outlive that threshold. Applying blind would point the meal at a
        // missing file AND delete its previous, valid photo: the real photo lost
        // to replace it with nothing. (Copilot catch on PR #186.)
        $user = $this->seedUser($em, 'swept-photo@test.local');
        $meal = $this->seedMeal($em, $user);
        $original = $this->attachPhoto($em, $meal);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/reanalyze');
        $form = $crawler->selectButton('Re-analyze this meal')->form();
        $form['meal_reanalyze_form[image]'][0]->upload($this->makeJpeg());
        $form['meal_reanalyze_form[replace_photo]']->tick();
        $client->submit($form);
        $review = $client->followRedirect();

        // Simulate the cleanup cron sweeping the parked upload.
        $parked = $this->parkedPhotoPath($review);
        unlink($parked);
        self::assertFileDoesNotExist($parked);

        // The review still renders, and says the photos are gone.
        $reloaded = $client->request('GET', '/meal/' . $meal->getId() . '/reanalyze/review');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('no longer available', $reloaded->html());

        $client->submit($reloaded->selectButton('Apply this estimate')->form());
        $this->assertResponseRedirects('/meal/' . $meal->getId());

        $em->clear();
        $fresh = $em->getRepository(MealEntry::class)->find($meal->getId());
        // The estimate landed (it was paid for) …
        self::assertSame(self::PROPOSED_CALORIES, $fresh->getNutritionData()->getCalories());
        // … and the meal's own photo survived untouched.
        self::assertSame('uploads/meals/' . basename($original), $fresh->getImagePath());
        self::assertFileExists($original);

        @unlink($original);
    }

    public function testAZeroConfidenceEstimateStillShowsItsConfidenceLine(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // 0.0 is a valid confidence, and "the AI has no confidence in this" is
        // the reading most worth surfacing — a truthiness check hid the whole
        // line (Copilot catch on PR #186).
        $user = $this->seedUser($em, 'zero-confidence@test.local');
        $meal = $this->seedMeal($em, $user);
        $client->loginUser($user);

        $review = $this->runReanalysis($client, $meal, FakeAnthropicResponder::ZERO_CONFIDENCE);
        self::assertStringContainsString('0% for this estimate', $review->html());
    }

    public function testTheChangeColumnCarriesNoGoodBadColouring(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // Painting rises red and falls green asserts a good/bad meaning that
        // nutrients don't have (more protein is usually wanted, more sodium
        // usually isn't), which would make an approval screen read as dietary
        // advice. The arrows carry direction; colour carries nothing.
        $user = $this->seedUser($em, 'neutral-diff@test.local');
        $meal = $this->seedMeal($em, $user);
        $client->loginUser($user);

        $review = $this->runReanalysis($client, $meal, 'Lentil soup, re-read for colours');
        $table = $review->filter('#reanalysis-diff')->html();

        self::assertStringContainsString('bi-arrow-', $table, 'direction is still shown');
        self::assertStringNotContainsString('text-danger', $table);
        self::assertStringNotContainsString('text-success', $table);
    }

    /**
     * Runs one description-based re-analysis and returns the review page.
     */
    private function runReanalysis(KernelBrowser $client, MealEntry $meal, string $description): Crawler
    {
        $crawler = $client->request('GET', '/meal/' . $meal->getId() . '/reanalyze');
        $client->submit($crawler->selectButton('Re-analyze this meal')->form([
            'meal_reanalyze_form[description]' => $description,
        ]));
        $this->assertResponseRedirects('/meal/' . $meal->getId() . '/reanalyze/review');

        return $client->followRedirect();
    }

    /** Server-side path of a photo shown in the review's "New photos" card. */
    private function parkedPhotoPath(Crawler $review): string
    {
        $src = (string) $review->filter('img[src*="uploads/meals/meal-reanalysis-"]')->attr('src');
        self::assertNotSame('', $src, 'the review shows the uploaded photo');

        return static::getContainer()->getParameter('uploads_dir') . '/' . basename($src);
    }

    /** Gives the meal a real file on disk as its photo, and returns that path. */
    private function attachPhoto(EntityManagerInterface $em, MealEntry $meal): string
    {
        $uploadsDir = static::getContainer()->getParameter('uploads_dir');
        $path = $uploadsDir . '/meal-test-' . bin2hex(random_bytes(8)) . '.jpg';
        copy($this->makeJpeg(), $path);

        $meal->setImagePath('uploads/meals/' . basename($path));
        $em->flush();

        return $path;
    }

    private function makeJpeg(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'reanalyze-meal') . '.jpg';
        $im = imagecreatetruecolor(24, 24);
        imagefilledrectangle($im, 0, 0, 23, 23, (int) imagecolorallocate($im, 120, 180, 90));
        imagejpeg($im, $path);

        return $path;
    }

    private function seedMeal(
        EntityManagerInterface $em,
        User $user,
        bool $withNutrition = true,
        bool $withDescription = true,
    ): MealEntry {
        $entry = new MealEntry();
        $entry->setUser($user);
        $entry->setMealType(MealType::Lunch);
        // Start of the user's local today, so the meal always falls inside the
        // current local month (the MealEditPhase2Test lesson: a relative offset
        // can cross the boundary in the first minutes of the 1st).
        $entry->setLoggedAt(
            (new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())))->setTime(0, 5),
        );
        if ($withDescription) {
            $entry->setAiDescription('Lentil soup with bread');
        }
        $entry->setIngredients([['name' => 'lentils', 'amount' => '1', 'unit' => 'cup']]);
        if ($withNutrition) {
            $entry->setNutritionData(NutritionData::fromArray([
                'calories'        => self::STORED_CALORIES,
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

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Phase Three Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}

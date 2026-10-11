<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\MealEntry;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\Panther\Client;

/**
 * The headline flow: upload a food photo → Confirm & Refine (Claude is mocked, so
 * it returns a confident estimate) → Save to log. Exercises real file upload,
 * the session-backed review screen, the portion control, and persistence.
 */
final class MealFlowTest extends E2ETestCase
{
    public function testPhotoToReviewToSave(): void
    {
        $image = $this->makeJpeg();

        $client = self::createPantherClient();
        $this->login($client);

        // 1. Upload a photo on the Log a Meal page.
        $client->request('GET', '/meal/log');
        $client->findElement(WebDriverBy::name('meal_entry_form[image][]'))->sendKeys($image);
        $client->findElement(WebDriverBy::id('submit-btn'))->click();

        // 2. Land on Confirm & Refine; the mock returned a confident estimate, so
        //    "Save to log" (name=accept) leads. The description should be visible.
        $client->waitFor('button[name="accept"]', 10);
        self::assertStringContainsString('/meal/clarify', $client->getCurrentURL());
        self::assertSelectorExists('input[name="portions"]');

        // 3. Save to log.
        $client->findElement(WebDriverBy::cssSelector('button[name="accept"]'))->click();

        // 4. Redirects to the saved meal page (/meal/{id}).
        $client->wait(10)->until(
            static fn ($c) => str_contains($c->getCurrentURL(), '/meal/')
                && !str_contains($c->getCurrentURL(), '/clarify') ? true : null
        );
        self::assertMatchesRegularExpression('#/meal/\d+#', $client->getCurrentURL());

        // 5. The meal persisted with the mocked nutrition.
        $this->em->clear();
        $meals = $this->em->getRepository(MealEntry::class)->findAll();
        self::assertCount(1, $meals);

        $nutrition = $meals[0]->getNutritionData();
        self::assertNotNull($nutrition);
        self::assertEqualsWithDelta(520.0, $nutrition->getCalories(), 0.01);
        self::assertEqualsWithDelta(46.0, $nutrition->getProtein(), 0.01);
    }

    public function testPortionMultiplierScalesNutrition(): void
    {
        $image = $this->makeJpeg();

        $client = self::createPantherClient();
        $this->login($client);

        $client->request('GET', '/meal/log');
        $client->findElement(WebDriverBy::name('meal_entry_form[image][]'))->sendKeys($image);
        $client->findElement(WebDriverBy::id('submit-btn'))->click();

        $client->waitFor('input[name="portions"]', 10);

        // Eat two servings → nutrition should double on save.
        $portions = $client->findElement(WebDriverBy::name('portions'));
        $portions->clear();
        $portions->sendKeys('2');
        $client->findElement(WebDriverBy::cssSelector('button[name="accept"]'))->click();

        $client->wait(10)->until(
            static fn ($c) => str_contains($c->getCurrentURL(), '/meal/')
                && !str_contains($c->getCurrentURL(), '/clarify') ? true : null
        );

        $this->em->clear();
        $meals = $this->em->getRepository(MealEntry::class)->findAll();
        self::assertCount(1, $meals);
        self::assertEqualsWithDelta(1040.0, $meals[0]->getNutritionData()->getCalories(), 0.01);
    }

    public function testBackdateWarningSurfacesWhenDateIsNotToday(): void
    {
        $image = $this->makeJpeg();

        $client = self::createPantherClient();
        $this->login($client);

        $client->request('GET', '/meal/log');
        $client->findElement(WebDriverBy::name('meal_entry_form[image][]'))->sendKeys($image);
        $client->findElement(WebDriverBy::id('submit-btn'))->click();
        $client->waitFor('#logged-at-input', 10);

        // Fresh (no-EXIF) photo → logged for today → the backdate warning is hidden.
        self::assertFalse($client->findElement(WebDriverBy::id('backdate-warning'))->isDisplayed());

        // Set the date to a past day (as a reused/older photo would) → warning appears.
        $client->executeScript(
            "var i=document.getElementById('logged-at-input');"
            . "i.value='2025-01-01T08:00';"
            . "i.dispatchEvent(new Event('input', {bubbles: true}));"
        );
        $client->waitForVisibility('#backdate-warning', 5);
        self::assertTrue($client->findElement(WebDriverBy::id('backdate-warning'))->isDisplayed());
    }

    public function testDuplicatePhotoSameTimeWarns(): void
    {
        $image = $this->makeJpeg();      // one source file → both uploads are byte-identical
        $when  = '2025-06-01T08:00';     // same date/time for both saves

        $client = self::createPantherClient();
        $this->login($client);

        $this->logMealAt($client, $image, $when);   // first save — fine
        $this->logMealAt($client, $image, $when);   // second — same photo + same time

        // The second save lands on the meal page with an actionable duplicate callout.
        $client->waitForVisibility('.alert-warning', 5);
        $callout = $client->findElement(WebDriverBy::cssSelector('.alert-warning'));
        self::assertStringContainsStringIgnoringCase('duplicate', $callout->getText());
        // The remedy is explicit: a Delete Entry button inside the callout.
        self::assertStringContainsStringIgnoringCase('delete entry', $callout->getText());
        self::assertNotNull(
            $callout->findElement(WebDriverBy::cssSelector('form[action*="/delete"] button[type="submit"]'))
        );
    }

    private function logMealAt(Client $client, string $image, string $datetimeLocal): void
    {
        $client->request('GET', '/meal/log');
        $client->findElement(WebDriverBy::name('meal_entry_form[image][]'))->sendKeys($image);
        $client->findElement(WebDriverBy::id('submit-btn'))->click();
        $client->waitFor('#logged-at-input', 10);
        // Pin the date so both saves share the same loggedAt.
        $client->executeScript(
            "var i=document.getElementById('logged-at-input'); i.value='{$datetimeLocal}';"
            . "i.dispatchEvent(new Event('input', {bubbles: true}));"
        );
        $client->findElement(WebDriverBy::cssSelector('button[name="accept"]'))->click();
        $client->wait(10)->until(
            static fn ($c) => str_contains($c->getCurrentURL(), '/meal/')
                && !str_contains($c->getCurrentURL(), '/clarify') ? true : null
        );
    }

    /** A throwaway JPEG on disk for the file-upload field (chromedriver reads it locally). */
    private function makeJpeg(): string
    {
        $path = sys_get_temp_dir() . '/e2e-meal-' . uniqid() . '.jpg';
        $im = imagecreatetruecolor(64, 64);
        imagefill($im, 0, 0, imagecolorallocate($im, 180, 140, 90));
        imagejpeg($im, $path);

        return $path;
    }
}

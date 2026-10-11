<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\SymptomEntry;
use App\Entity\SymptomType;
use App\Entity\User;
use App\Service\SymptomTypeManager;
use Facebook\WebDriver\WebDriverBy;

/**
 * The opt-in symptom check-in: enabling a built-in symptom and saving a level.
 */
final class SymptomTest extends E2ETestCase
{
    public function testToggleEnablesSymptom(): void
    {
        $client = self::createPantherClient();
        $this->login($client);

        // Visiting the page seeds the built-ins (disabled). Flip the first switch on.
        $client->request('GET', '/symptoms');
        $client->findElement(WebDriverBy::cssSelector('.form-switch input[type="checkbox"]'))->click();

        // onchange auto-submits → reload; an enabled symptom now shows a slider.
        $client->waitFor('input[type="range"]', 10);

        $this->em->clear();
        $enabled = array_filter(
            $this->em->getRepository(SymptomType::class)->findAll(),
            static fn (SymptomType $t): bool => $t->isEnabled()
        );
        self::assertCount(1, $enabled);
    }

    public function testSaveCheckin(): void
    {
        // Seed the built-ins and enable one before loading the page.
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::USER_EMAIL]);
        self::getContainer()->get(SymptomTypeManager::class)->ensureDefaultsFor($user);
        $type = $this->em->getRepository(SymptomType::class)->findAll()[0];
        $type->setEnabled(true);
        $this->em->flush();
        $typeId = $type->getId();

        $client = self::createPantherClient();
        $this->login($client);

        $client->request('GET', '/symptoms');
        $client->waitFor("input[name=\"level_{$typeId}\"]", 10);

        // Set the slider to 7 and submit (server-rendered CSRF is already in the form).
        $client->executeScript(
            "var i=document.querySelector('input[name=\"level_{$typeId}\"]');"
            . " i.value=7; i.closest('form').submit();"
        );

        $client->wait(10)->until(
            static fn ($c) => str_contains($c->getCurrentURL(), '/symptoms')
                && !str_contains($c->getCurrentURL(), '/save') ? true : null
        );

        $this->em->clear();
        $entries = $this->em->getRepository(SymptomEntry::class)->findAll();
        self::assertCount(1, $entries);
        self::assertSame(7, $entries[0]->getLevel());
    }
}

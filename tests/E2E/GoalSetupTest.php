<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\NutritionGoal;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverKeys;

/**
 * Conversational goal setup. The mocked coach returns a fenced goal-proposal on
 * the first turn (the third Claude branch), so one message surfaces the
 * "Create this goal" confirmation, which persists a NutritionGoal.
 */
final class GoalSetupTest extends E2ETestCase
{
    public function testChatProposalToConfirmedGoal(): void
    {
        $client = self::createPantherClient();
        $this->login($client);

        $client->request('GET', '/goals/new');

        // Send a message; the single-input chat form submits on Enter.
        $client->findElement(WebDriverBy::name('message'))
            ->sendKeys('I want to eat more fiber' . WebDriverKeys::ENTER);

        // The mock's proposal makes the confirm form appear after the redirect.
        $client->waitFor('#confirm-form', 10);
        $client->findElement(WebDriverBy::cssSelector('#confirm-form button'))->click();

        // Confirm persists the goal and redirects to /goals/{id}.
        $client->wait(10)->until(
            static fn ($c) => preg_match('#/goals/\d+#', $c->getCurrentURL()) ? true : null
        );

        $this->em->clear();
        $goals = $this->em->getRepository(NutritionGoal::class)->findAll();
        self::assertCount(1, $goals);
        self::assertSame('More fiber', $goals[0]->getName());
    }
}

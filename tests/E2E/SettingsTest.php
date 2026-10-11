<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;

/**
 * Settings persistence — changing the timezone updates the user and is reflected
 * back on the form.
 */
final class SettingsTest extends E2ETestCase
{
    public function testChangeTimezone(): void
    {
        $client = self::createPantherClient();
        $this->login($client);

        $client->request('GET', '/settings/timezone');
        $client->submitForm('Save timezone', [
            'timezone_form[timezone]' => 'Europe/London',
        ]);
        // Wait for the post-redirect success flash so the DB read can't race the save.
        $client->waitForVisibility('.alert-success', 10);

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::USER_EMAIL]);
        self::assertSame('Europe/London', $user->getTimezone());
    }
}

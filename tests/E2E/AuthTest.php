<?php

declare(strict_types=1);

namespace App\Tests\E2E;

/**
 * Authentication edge cases: wrong credentials, logout, and the UserChecker
 * blocking disabled accounts.
 */
final class AuthTest extends E2ETestCase
{
    public function testWrongPasswordStaysOnLogin(): void
    {
        $client = self::createPantherClient();
        $client->request('GET', '/login');
        $client->submitForm('Sign In', [
            '_username' => self::USER_EMAIL,
            '_password' => 'wrong-password',
        ]);

        self::assertStringContainsString('/login', $client->getCurrentURL());
        self::assertSelectorNotExists('a[href="/logout"]');
    }

    public function testLogout(): void
    {
        $client = self::createPantherClient();
        $this->login($client);
        self::assertSelectorExists('a[href="/logout"]');

        $client->request('GET', '/logout');
        $client->request('GET', '/');

        // Back to anonymous → bounced to login.
        self::assertStringContainsString('/login', $client->getCurrentURL());
    }

    public function testDisabledUserCannotLogIn(): void
    {
        $user = $this->seedUser('disabled@test.local');
        $user->setActive(false);
        $this->em->flush();

        $client = self::createPantherClient();
        $client->request('GET', '/login');
        $client->submitForm('Sign In', [
            '_username' => 'disabled@test.local',
            '_password' => self::PASSWORD,
        ]);

        self::assertStringContainsString('/login', $client->getCurrentURL());
        self::assertSelectorNotExists('a[href="/logout"]');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\E2E;

/**
 * Validates the harness end to end: Panther starts headless Chrome, the built-in
 * server boots in the test env against nutrition_test, anonymous access is
 * gated, and a seeded user can log in.
 */
final class SmokeTest extends E2ETestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = self::createPantherClient();
        $client->request('GET', '/');

        self::assertStringContainsString('/login', $client->getCurrentURL());
    }

    public function testLoginPageRenders(): void
    {
        $client = self::createPantherClient();
        $client->request('GET', '/login');

        self::assertSelectorExists('input[name="_username"]');
        self::assertSelectorExists('input[name="_password"]');
    }

    public function testSeededUserCanLogIn(): void
    {
        $client = self::createPantherClient();
        $this->login($client);

        // Dashboard is the root route; the logout link only renders when authenticated.
        self::assertSelectorExists('a[href="/logout"]');
        self::assertStringNotContainsString('/login', $client->getCurrentURL());
    }
}

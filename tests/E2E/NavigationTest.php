<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Broad smoke coverage: every primary authenticated page renders normally (the
 * authenticated layout's logout link is present, so it isn't an exception page
 * or a redirect to /login) for a freshly-seeded user with no data — i.e. all the
 * empty states hold up.
 */
final class NavigationTest extends E2ETestCase
{
    /** @return iterable<string, array{string}> */
    public static function authenticatedPages(): iterable
    {
        yield 'dashboard' => ['/'];
        yield 'log a meal' => ['/meal/log'];
        yield 'favorites' => ['/meal/favorites'];
        yield 'trends' => ['/trends'];
        yield 'health metrics' => ['/health-metrics'];
        yield 'supplements' => ['/supplements'];
        yield 'supplement scan' => ['/supplements/scan'];
        yield 'symptoms' => ['/symptoms'];
        yield 'goals' => ['/goals'];
        yield 'new goal (chat)' => ['/goals/new'];
        yield 'new goal (manual)' => ['/goals/new/manual'];
        yield 'glucose insights' => ['/insights/glucose'];
        yield 'settings: hub' => ['/settings'];
        yield 'settings: timezone' => ['/settings/timezone'];
        yield 'settings: units' => ['/settings/units'];
        yield 'settings: GLP-1 mode' => ['/settings/glp1'];
        yield 'settings: voice & tone' => ['/settings/tone'];
        yield 'settings: notifications' => ['/settings/notifications'];
        yield 'settings: API tokens' => ['/settings/api-tokens'];
        yield 'settings: subscription' => ['/settings/subscription'];
        yield 'settings: account' => ['/settings/account'];
    }

    #[DataProvider('authenticatedPages')]
    public function testPageRenders(string $path): void
    {
        $client = self::createPantherClient();
        $this->login($client);
        $client->request('GET', $path);

        self::assertStringNotContainsString('/login', $client->getCurrentURL());
        self::assertSelectorExists('a[href="/logout"]');
    }

    public function testAdminPagesRenderForAdmin(): void
    {
        $this->seedUser(self::ADMIN_EMAIL, ['ROLE_USER', 'ROLE_ADMIN']);

        $client = self::createPantherClient();
        $this->login($client, self::ADMIN_EMAIL);

        foreach (['/admin/users', '/admin/reports/favorites'] as $path) {
            $client->request('GET', $path);
            self::assertStringNotContainsString('/login', $client->getCurrentURL());
            self::assertSelectorExists('a[href="/logout"]');
        }
    }

    public function testAdminPageForbiddenForRegularUser(): void
    {
        $client = self::createPantherClient();
        $this->login($client);
        $client->request('GET', '/admin/users');

        // Access-control denies ROLE_USER → not the admin page.
        self::assertSelectorNotExists('table#admin-users, [data-admin-users]');
    }
}

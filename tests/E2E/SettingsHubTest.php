<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Controller\SettingsController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The /settings hub: renders every category from SettingsController::CATEGORIES with
 * a working link, and the navbar dropdown links to it instead of listing every page
 * flat. In-process WebTestCase (no JS needed), kept under tests/E2E per the app's
 * convention for DB-needing, non-Panther tests.
 */
final class SettingsHubTest extends WebTestCase
{
    use DbResetTrait;

    public function testHubRendersEveryCategoryAndItem(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'hub@test.local');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/settings');

        self::assertResponseIsSuccessful();

        $router = $client->getContainer()->get('router');
        $translator = $client->getContainer()->get('translator');
        foreach (SettingsController::CATEGORIES as $category) {
            // CATEGORIES labels are translation keys since the i18n extraction.
            self::assertStringContainsString(
                $translator->trans($category['label']),
                $crawler->filter('body')->text(),
            );
            foreach ($category['items'] as $item) {
                $href = $router->generate($item['route']);
                self::assertGreaterThan(
                    0,
                    $crawler->filter(sprintf('a[href="%s"]', $href))->count(),
                    sprintf('expected a link to %s', $item['route']),
                );
            }
        }
    }

    public function testNavbarLinksToTheHubInsteadOfEveryPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'nav@test.local');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/');

        self::assertCount(1, $crawler->filter('a[href="/settings"]'));
        self::assertCount(0, $crawler->filter('a[href="/settings/timezone"]'));
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
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Password-UX quick wins: always-on remember-me (no checkbox, cookie set on every
 * form login) and the show/hide password toggle on the auth pages.
 *
 * In-process (KernelBrowser, not Panther) — needs the real DB for the login POST,
 * so it lives in the e2e job; the test env's stateful CSRF is satisfied by
 * submitting the rendered form. The breached-password (HIBP) constraint is NOT
 * covered here: the validator is disabled in the test env (validator.yaml) so the
 * suite never makes network calls.
 */
final class PasswordUxTest extends WebTestCase
{
    use DbResetTrait;

    public function testLoginHasNoRememberMeCheckboxButSetsRememberMeCookie(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $this->seedUser('longsession@test.local');

        $crawler = $client->request('GET', '/login');
        $this->assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('input[name="_remember_me"]'),
            'remember-me checkbox removed (always_remember_me)'
        );

        $client->submitForm('Sign In', [
            '_username' => 'longsession@test.local',
            '_password' => 'password123',
        ]);
        $this->assertResponseRedirects();

        $cookie = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($cookie, 'remember-me cookie set without a checkbox');
        self::assertGreaterThan(
            time() + 170 * 86400,
            (int) $cookie->getExpiresTime(),
            'remember-me cookie lives ~180 days'
        );
    }

    public function testAuthPagesRenderThePasswordToggle(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        foreach (['/login', '/register'] as $path) {
            $client->request('GET', $path);
            $this->assertResponseIsSuccessful();
            self::assertStringContainsString(
                'Show password',
                (string) $client->getResponse()->getContent(),
                sprintf('%s includes the password toggle script', $path)
            );
        }
    }

    public function testRegistrationShowsPassphraseTip(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $client->request('GET', '/register');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString(
            'three or four random words',
            (string) $client->getResponse()->getContent(),
            'registration shows the passphrase tip'
        );
    }

    private function seedUser(string $email): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Long Session');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}

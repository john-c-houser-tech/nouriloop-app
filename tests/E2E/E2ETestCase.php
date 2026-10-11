<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base class for the real-browser (Panther + headless Chrome) E2E suite.
 *
 * Each test gets a clean nutrition_test DB (truncated in setUp) seeded with a
 * known user, and a fresh Panther client driving real Chrome against a built-in
 * web server running in the `test` environment (where Claude is mocked — see
 * FakeAnthropicResponder / config/services_test.yaml).
 */
abstract class E2ETestCase extends PantherTestCase
{
    use DbResetTrait;

    protected const PASSWORD = 'password123';
    protected const USER_EMAIL = 'e2e@test.local';
    protected const ADMIN_EMAIL = 'admin@test.local';

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel(['environment' => 'test']);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->truncateAll($this->em);
        $this->seedUser(self::USER_EMAIL);
    }

    protected function tearDown(): void
    {
        // Panther reuses one browser across the whole class, so cookies (the
        // session + the stateless-CSRF double-submit cookies) would otherwise
        // leak between tests: a stale session cookie (NOURILOOP_SESSID) points at a server session whose
        // user row the next setUp truncated, silently de-authenticating the next
        // test. Land on a known same-origin page first so the delete reliably
        // targets the app domain.
        foreach (self::$pantherClients as $client) {
            try {
                $client->request('GET', '/login');
                $client->manage()->deleteAllCookies();
            } catch (\Throwable) {
                // No active page/session to clear — nothing to do.
            }
        }

        parent::tearDown();
        // Free the kernel so the next test re-boots cleanly.
        self::ensureKernelShutdown();
    }

    protected function seedUser(string $email, array $roles = ['ROLE_USER'], string $tz = 'America/New_York'): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('E2E Tester');
        $user->setRoles($roles);
        $user->setActive(true);
        $user->setTimezone($tz);
        // Verified, like the grandfathered pre-verification population — keeps the
        // unverified-email banner out of every Panther flow (tests exercising the
        // unverified state seed their own user; see EmailVerificationTest).
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Log in through the real login form (server-rendered CSRF token, real JS). */
    protected function login(Client $client, string $email = self::USER_EMAIL): Client
    {
        $client->request('GET', '/login');
        $client->submitForm('Sign In', [
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]);
        // Block until authentication is confirmed (the logout link is present in
        // the DOM only when logged in) so a follow-up navigation can't race ahead
        // of the session being established.
        $client->waitFor('a[href="/logout"]', 10);

        return $client;
    }
}

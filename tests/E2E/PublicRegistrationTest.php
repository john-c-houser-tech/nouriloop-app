<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Open public registration (soft-launch). In-process so it runs in the DB-having e2e
 * job without a browser. Turnstile is a no-op in the test env (no keys), so no CAPTCHA
 * token is needed; the valid-/invalid-code paths live in RegistrationTest (Panther) and
 * BetaGrandfatheringTest.
 */
final class PublicRegistrationTest extends WebTestCase
{
    use DbResetTrait;

    public function testAnyoneCanRegisterWithoutACodeAsFreeTier(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $client->request('GET', '/register');
        $client->submitForm('Create Account', [
            'registration_form[displayName]' => 'Free Tier Person',
            'registration_form[email]' => 'free@test.local',
            'registration_form[invitationCode]' => '',
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
        ]);

        // Auto-login: registration signs the new user in and lands on the dashboard
        // (no bounce through the login page).
        $this->assertResponseRedirects('/');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();

        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'free@test.local']);
        self::assertNotNull($user, 'a no-code signup creates the account');
        self::assertSame(SubscriptionTier::Free, $user->getSubscriptionTier());
        self::assertNull($user->getCompTier(), 'no code → no Loop+ comp');
    }

    public function testHoneypotSubmissionCreatesNoAccount(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $client->request('GET', '/register');
        $client->submitForm('Create Account', [
            'registration_form[displayName]' => 'Spam Bot',
            'registration_form[email]' => 'bot@test.local',
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
            'registration_form[website]' => 'http://spam.example',
        ]);

        // Looks successful to the bot, but no account is created. (Real signups
        // auto-login to the dashboard; there's no user to sign in here, so the
        // fake success keeps the old login-page redirect.)
        $this->assertResponseRedirects('/login');
        $em->clear();
        self::assertNull($em->getRepository(User::class)->findOneBy(['email' => 'bot@test.local']));
    }

    public function testInvalidCodeStillRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $client->request('GET', '/register');
        $client->submitForm('Create Account', [
            'registration_form[displayName]' => 'Hopeful Person',
            'registration_form[email]' => 'hopeful@test.local',
            'registration_form[invitationCode]' => 'not-a-real-code',
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
        ]);

        // A non-blank but invalid code is rejected (422 re-render), no account.
        $this->assertResponseStatusCodeSame(422);
        $em->clear();
        self::assertNull($em->getRepository(User::class)->findOneBy(['email' => 'hopeful@test.local']));
    }
}

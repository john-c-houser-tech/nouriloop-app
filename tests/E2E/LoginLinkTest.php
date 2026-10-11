<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Passwordless "email me a sign-in link" (magic links). In-process WebTestCase —
 * needs the real DB + the mailer message logger; Turnstile no-ops without keys in
 * test, and the test env's stateful CSRF is satisfied by submitting rendered forms.
 *
 * Covers: the request → email → confirm-page → POST-authenticate round trip
 * (including the 180-day remember-me cookie riding along), single-use enforcement,
 * the no-enumeration uniform outcome, honeypot, disabled accounts, the per-IP rate
 * limit, and the scanner-protection GET behavior.
 */
final class LoginLinkTest extends WebTestCase
{
    use DbResetTrait;

    public function testFullSignInRoundTrip(): void
    {
        $client = static::createClient();
        $this->resetState();
        $this->seedUser('linky@test.local');

        // Regression guard: an anonymous GET /logout used to be access-denied,
        // which saved /logout as the session's post-login target path — the next
        // successful sign-in then redirected through it and instantly logged the
        // user back out. /logout is PUBLIC_ACCESS now, so this must be harmless.
        $client->request('GET', '/logout');
        $this->assertResponseRedirects();

        // Request a link.
        $client->request('GET', '/login/link');
        $this->assertResponseIsSuccessful();
        $client->submitForm('Email me a sign-in link', [
            'login_link_request_form[email]' => 'linky@test.local',
        ]);
        $this->assertResponseRedirects('/login/link/sent');
        $this->assertEmailCount(1);

        $url = $this->extractLinkFromEmail();
        self::assertStringContainsString('/login/check', $url);

        // A GET (what an email scanner does) renders the confirm page — it must
        // NOT authenticate or consume the link.
        $crawler = $client->request('GET', $url);
        $this->assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('form button')->count(), 'confirm page shows the button');

        // The button's POST signs the user in and lands on the dashboard.
        $client->submitForm('Finish signing in');
        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertResponseIsSuccessful();

        // The login-link authenticator carries a RememberMeBadge, so with
        // always_remember_me the long-lived cookie rides along.
        self::assertNotNull(
            $client->getCookieJar()->get('REMEMBERME'),
            'link sign-in sets the remember-me cookie'
        );

        // An authenticated page renders (not a redirect back to /login).
        $client->request('GET', '/settings');
        $this->assertResponseIsSuccessful();
    }

    public function testLinkIsSingleUse(): void
    {
        $client = static::createClient();
        $this->resetState();
        $this->seedUser('once@test.local');

        $client->request('GET', '/login/link');
        $client->submitForm('Email me a sign-in link', [
            'login_link_request_form[email]' => 'once@test.local',
        ]);
        $url = $this->extractLinkFromEmail();

        // First use: sign in, then log out.
        $client->request('GET', $url);
        $client->submitForm('Finish signing in');
        $this->assertResponseRedirects();
        $client->request('GET', '/logout');

        // Second use of the same link must fail back to the login page.
        $client->request('POST', $url);
        $this->assertResponseRedirects('/login');
        $crawler = $client->followRedirect();
        self::assertGreaterThan(
            0,
            $crawler->filter('.alert-danger')->count(),
            'reusing a consumed link shows the login-page error'
        );
    }

    public function testUnknownEmailGetsSameOutcomeAndNoMail(): void
    {
        $client = static::createClient();
        $this->resetState();

        $client->request('GET', '/login/link');
        $client->submitForm('Email me a sign-in link', [
            'login_link_request_form[email]' => 'nobody@test.local',
        ]);
        $this->assertResponseRedirects('/login/link/sent');
        $this->assertEmailCount(0);
    }

    public function testDisabledAccountGetsNoMail(): void
    {
        $client = static::createClient();
        $this->resetState();
        $user = $this->seedUser('disabled@test.local');
        $user->setActive(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/login/link');
        $client->submitForm('Email me a sign-in link', [
            'login_link_request_form[email]' => 'disabled@test.local',
        ]);
        $this->assertResponseRedirects('/login/link/sent');
        $this->assertEmailCount(0);
    }

    public function testHoneypotLooksSuccessfulButSendsNothing(): void
    {
        $client = static::createClient();
        $this->resetState();
        $this->seedUser('honey@test.local');

        $client->request('GET', '/login/link');
        $client->submitForm('Email me a sign-in link', [
            'login_link_request_form[email]' => 'honey@test.local',
            'login_link_request_form[website]' => 'https://spam.example',
        ]);
        $this->assertResponseRedirects('/login/link/sent');
        $this->assertEmailCount(0);
    }

    public function testRateLimitTripsOnSixthRequest(): void
    {
        $client = static::createClient();
        $this->resetState();
        $this->seedUser('limited@test.local');

        for ($i = 0; $i < 5; $i++) {
            $client->request('GET', '/login/link');
            $client->submitForm('Email me a sign-in link', [
                'login_link_request_form[email]' => 'limited@test.local',
            ]);
            $this->assertResponseRedirects('/login/link/sent');
        }

        $client->request('GET', '/login/link');
        $client->submitForm('Email me a sign-in link', [
            'login_link_request_form[email]' => 'limited@test.local',
        ]);
        $this->assertResponseStatusCodeSame(429);
    }

    /**
     * "Continue on my phone": a signed-in user mails a link to their OWN address.
     * The recipient comes from the session, never the request, so there's nothing
     * to point at someone else's inbox.
     */
    public function testContinueOnPhoneMailsTheSignedInUsersOwnAddress(): void
    {
        $client = static::createClient();
        $this->resetState();
        $user = $this->seedUser('phone@test.local');
        // The per-user bucket too: TRUNCATE resets the auto-increment, so this user
        // gets the same id every run and the persistent limiter cache would carry
        // its count over (5/hour — a sixth local run in an hour failed).
        static::getContainer()->get('limiter.login_link')
            ->create('login-link-user-' . $user->getId())->reset();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/');
        $form = $crawler->filter('form[action$="/login/link/to-phone"]')->form();
        $client->submit($form);

        $this->assertResponseRedirects('/');
        $this->assertEmailCount(1);
        $this->assertEmailAddressContains(
            $this->getMailerMessages()[0],
            'To',
            'phone@test.local',
        );
        self::assertStringContainsString('/login/check', $this->extractLinkFromEmail());
    }

    public function testContinueOnPhoneRejectsAForgedTokenAndSendsNothing(): void
    {
        $client = static::createClient();
        $this->resetState();
        $client->loginUser($this->seedUser('forged@test.local'));

        $client->request('POST', '/login/link/to-phone', ['_token' => 'nope']);

        $this->assertResponseRedirects('/');
        $this->assertEmailCount(0);
    }

    /**
     * `^/login` is PUBLIC_ACCESS, so the action's own ROLE_USER assertion is the
     * only thing standing between an anonymous POST and the mailer.
     */
    public function testContinueOnPhoneIsClosedToAnonymousVisitors(): void
    {
        $client = static::createClient();
        $this->resetState();

        $client->request('POST', '/login/link/to-phone');

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
        $this->assertEmailCount(0);
    }

    public function testCheckWithoutLinkParamsRedirectsToLogin(): void
    {
        $client = static::createClient();
        $this->resetState();

        $client->request('GET', '/login/check');
        $this->assertResponseRedirects('/login');
    }

    /** Truncate the DB and clear the per-IP limiter so state can't leak between tests. */
    private function resetState(): void
    {
        $this->truncateAll(static::getContainer()->get(EntityManagerInterface::class));
        static::getContainer()->get('limiter.login_link')
            ->create('login-link-ip-127.0.0.1')->reset();
    }

    private function extractLinkFromEmail(): string
    {
        $messages = $this->getMailerMessages();
        self::assertNotEmpty($messages, 'a sign-in email was sent');
        $html = $messages[array_key_last($messages)]->getHtmlBody();
        self::assertIsString($html);
        self::assertSame(
            1,
            preg_match('/href="([^"]*\/login\/check[^"]*)"/', $html, $m),
            'email contains the sign-in link'
        );

        return html_entity_decode($m[1]);
    }

    private function seedUser(string $email): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Link Person');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}

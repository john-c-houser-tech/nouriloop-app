<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Email ownership verification (soft-gate). In-process WebTestCase — real DB +
 * mailer message logger. Covers: new registrations start unverified with a
 * confirm login-link in the welcome email; consuming it verifies (the
 * LoginSuccessEvent listener) and clears the banner; a completed password reset
 * verifies; the banner's resend sends + rate-limits; and the digest's email
 * channel is withheld for unverified users while in-app delivery still happens.
 */
final class EmailVerificationTest extends WebTestCase
{
    use DbResetTrait;

    public function testRegistrationConfirmLinkVerifiesAndClearsBanner(): void
    {
        $client = static::createClient();
        $this->resetState();

        // Register (no beta code → Free tier). The welcome email must carry a
        // "confirm" login link, and the account starts unverified.
        $client->request('GET', '/register');
        $client->submitForm('Create Account', [
            'registration_form[displayName]' => 'Fresh Face',
            'registration_form[email]' => 'fresh@test.local',
            'registration_form[plainPassword][first]' => 'a-passphrase-here',
            'registration_form[plainPassword][second]' => 'a-passphrase-here',
        ]);
        $this->assertResponseRedirects('/');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'fresh@test.local']);
        self::assertNotNull($user);
        self::assertFalse($user->isEmailVerified(), 'new registrations start unverified');

        $confirmUrl = $this->extractConfirmLink('Welcome to NouriLoop');

        // Registration auto-logs the user in — the banner must show while unverified.
        $client->followRedirect();
        self::assertStringContainsString(
            'Resend confirmation email',
            (string) $client->getResponse()->getContent(),
            'unverified user sees the banner'
        );

        // Consume the welcome email's confirm link (GET confirm page → POST).
        $client->request('GET', '/logout');
        $client->request('GET', $confirmUrl);
        $this->assertResponseIsSuccessful();
        $client->submitForm('Finish signing in');
        $this->assertResponseRedirects();
        $client->followRedirect();

        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'fresh@test.local']);
        self::assertTrue($user->isEmailVerified(), 'consuming the confirm link verifies');

        self::assertStringNotContainsString(
            'Resend confirmation email',
            (string) $client->getResponse()->getContent(),
            'banner gone once verified'
        );
    }

    public function testConfirmLinkWorksWhileAlreadyLoggedIn(): void
    {
        // Regression (hit in prod on launch day): register → password login →
        // open the confirm link. check() used to bounce any logged-in visitor
        // to the dashboard before rendering the confirm page, so the link was
        // never consumed and the user could not verify.
        $client = static::createClient();
        $this->resetState();
        $user = $this->seedUser('stilloggedin@test.local', verified: false);
        static::getContainer()->get('limiter.email_verification')
            ->create('verify-email-user-' . $user->getId())->reset();

        $client->request('GET', '/login');
        $client->submitForm('Sign In', [
            '_username' => 'stilloggedin@test.local',
            '_password' => 'password123',
        ]);
        $client->followRedirect();

        // Get a confirm link via the banner's resend, then open it WITHOUT
        // logging out.
        $client->submitForm('Resend confirmation email');
        $confirmUrl = $this->extractConfirmLink('Confirm your NouriLoop email');

        $client->request('GET', $confirmUrl);
        $this->assertResponseIsSuccessful('confirm page renders even for a logged-in user');

        $client->submitForm('Finish signing in');
        $this->assertResponseRedirects();
        $client->followRedirect();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'stilloggedin@test.local']);
        self::assertTrue($user->isEmailVerified(), 'link consumed while logged in verifies');
        self::assertStringNotContainsString(
            'Resend confirmation email',
            (string) $client->getResponse()->getContent(),
            'banner gone once verified'
        );
    }

    public function testCompletedPasswordResetVerifies(): void
    {
        $client = static::createClient();
        $this->resetState();
        $this->seedUser('forgetful@test.local', verified: false);

        $client->request('GET', '/reset-password');
        $client->submitForm('Send reset link', [
            'reset_password_request_form[email]' => 'forgetful@test.local',
        ]);

        $html = $this->lastEmailHtml();
        self::assertSame(1, preg_match('/href="([^"]*\/reset-password\/reset\/[^"]+)"/', $html, $m));

        // The bundle stores the token in the session and redirects to the plain
        // reset form.
        $client->request('GET', html_entity_decode($m[1]));
        $client->followRedirect();
        $client->submitForm('Reset password', [
            'change_password_form[plainPassword][first]' => 'brand-new-words',
            'change_password_form[plainPassword][second]' => 'brand-new-words',
        ]);
        $this->assertResponseRedirects('/login');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'forgetful@test.local']);
        self::assertTrue($user->isEmailVerified(), 'completing a reset proves inbox control');
    }

    public function testResendSendsEmailAndRateLimits(): void
    {
        $client = static::createClient();
        $this->resetState();
        $user = $this->seedUser('nagged@test.local', verified: false);
        static::getContainer()->get('limiter.email_verification')
            ->create('verify-email-user-' . $user->getId())->reset();

        $client->request('GET', '/login');
        $client->submitForm('Sign In', [
            '_username' => 'nagged@test.local',
            '_password' => 'password123',
        ]);
        $client->followRedirect();

        // Three resends pass the limiter and send mail…
        for ($i = 0; $i < 3; $i++) {
            $client->submitForm('Resend confirmation email');
            $this->assertResponseRedirects();
            $this->assertEmailCount(1);
            $client->followRedirect();
        }

        // …the fourth is rate-limited: no email, error flash.
        $client->submitForm('Resend confirmation email');
        $this->assertEmailCount(0);
        $crawler = $client->followRedirect();
        self::assertStringContainsString(
            'sent recently',
            (string) $client->getResponse()->getContent(),
            'rate-limited resend explains itself'
        );
    }

    public function testDigestEmailWithheldForUnverifiedButInAppStillDelivered(): void
    {
        static::createClient();
        $this->resetState();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Stale-metrics notice fires for a user with no health readings at all —
        // hour-independent, so the test needs no local-time gymnastics.
        $user = $this->seedUser('unverified@test.local', verified: false);
        $user->setNotifyStaleMetrics(true);
        $em->flush();

        $service = static::getContainer()->get(NotificationService::class);
        $sent = $service->sendDueNotifications($user, new \DateTimeImmutable());

        self::assertNotEmpty($sent, 'the stale-metrics notice is due');
        $this->assertEmailCount(0);
        self::assertGreaterThan(
            0,
            static::getContainer()->get(NotificationRepository::class)->countUnread($user),
            'in-app notice still delivered to an unverified user'
        );

        // Same conditions, verified user → the digest email goes out.
        $verified = $this->seedUser('verified@test.local', verified: true);
        $verified->setNotifyStaleMetrics(true);
        $em->flush();

        $service->sendDueNotifications($verified, new \DateTimeImmutable());
        $this->assertEmailCount(1);
    }

    /** Truncate the DB (and note: limiters are reset per-test where needed). */
    private function resetState(): void
    {
        $this->truncateAll(static::getContainer()->get(EntityManagerInterface::class));
    }

    private function extractConfirmLink(string $subject): string
    {
        foreach ($this->getMailerMessages() as $message) {
            if ($message->getSubject() === $subject) {
                $html = $message->getHtmlBody();
                self::assertIsString($html);
                self::assertSame(
                    1,
                    preg_match('/href="([^"]*\/login\/check[^"]*)"/', $html, $m),
                    'email carries the confirm login link'
                );

                return html_entity_decode($m[1]);
            }
        }

        self::fail(sprintf('no email with subject "%s"', $subject));
    }

    private function lastEmailHtml(): string
    {
        $messages = $this->getMailerMessages();
        self::assertNotEmpty($messages);
        $html = $messages[array_key_last($messages)]->getHtmlBody();
        self::assertIsString($html);

        return $html;
    }

    private function seedUser(string $email, bool $verified): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Verification Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setEmailVerifiedAt($verified ? new \DateTimeImmutable() : null);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}

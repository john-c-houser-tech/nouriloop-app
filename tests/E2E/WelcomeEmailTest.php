<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Service\InvitationCodeManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A successful registration must send the new user a welcome email.
 *
 * In-process (KernelBrowser, not Panther) so the registration runs in the same
 * kernel as the test and the mailer message logger can be asserted. Lives under
 * tests/E2E because it needs the real DB (the unit CI job has none); the test env
 * uses stateful CSRF, which a non-JS BrowserKit client satisfies automatically.
 */
final class WelcomeEmailTest extends WebTestCase
{
    use DbResetTrait;

    public function testWelcomeEmailSentOnSuccessfulRegistration(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->truncateAll($em);

        // Mint a valid, single-use invitation code.
        $creator = $this->seedUser('creator@test.local');
        $code = static::getContainer()->get(InvitationCodeManager::class)->create($creator);
        $em->flush();

        $client->request('GET', '/register');
        $client->submitForm('Create Account', [
            'registration_form[displayName]' => 'Welcomed Person',
            'registration_form[email]' => 'welcomed@test.local',
            'registration_form[invitationCode]' => $code->getCode(),
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
        ]);

        $this->assertResponseRedirects('/');

        // Two emails: the user's welcome, plus the admin notice to the configured
        // ADMIN_EMAIL (always sent now — recipient is fixed, not the admin account).
        // Matched by subject so the assertion doesn't depend on send order.
        $this->assertEmailCount(2);
        $bySubject = [];
        foreach ($this->getMailerMessages() as $message) {
            $bySubject[$message->getSubject()] = $message;
        }

        $welcome = $bySubject['Welcome to NouriLoop'] ?? null;
        self::assertNotNull($welcome, 'welcome email sent');
        $this->assertEmailAddressContains($welcome, 'To', 'welcomed@test.local');
        // A message that sets no Reply-To still gets the global MAILER_REPLY_TO.
        $this->assertEmailAddressContains($welcome, 'Reply-To', 'noreply@nouriloop.com');
        $this->assertEmailHtmlBodyContains($welcome, 'Welcome Welcomed Person,');

        // The founder's letter must run uninterrupted: the verification CTA sits
        // in a separated P.S. block BELOW the sign-off, never after the greeting.
        $html = $welcome->getHtmlBody();
        self::assertIsString($html);
        $confirmPos = strpos($html, 'Confirm your email');
        // The letter's sign-off deliberately stays john@nouriloop.com (a personal
        // note from John) even though every other contact point is info@.
        $signoffPos = strpos($html, 'mailto:john@nouriloop.com');
        self::assertNotFalse($confirmPos, 'welcome email carries the confirm CTA');
        self::assertNotFalse($signoffPos, 'letter sign-off present');
        self::assertGreaterThan($signoffPos, $confirmPos, 'confirm CTA comes after the sign-off');

        $adminNotice = $bySubject['New NouriLoop user registered'] ?? null;
        self::assertNotNull($adminNotice, 'admin notice sent');
        $this->assertEmailAddressContains($adminNotice, 'To', 'info@nouriloop.com');
    }

    private function seedUser(string $email): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Code Creator');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}

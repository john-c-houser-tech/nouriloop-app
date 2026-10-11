<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Settings ▸ Profile (in-process, real DB + mailer logger): name edits save
 * plainly; email changes are confirm-first — password-gated, uniqueness- and
 * deliverability-checked at request time, applied only when the NEW address's
 * signed 24 h link is opened by the same signed-in account, with a heads-up
 * notice to the old inbox.
 */
final class ProfileSettingsTest extends WebTestCase
{
    use DbResetTrait;

    public function testNameOnlyChangeSavesWithoutPassword(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'name@test.local');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/settings/profile');
        $this->assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Save Changes')->form([
            'profile_form[displayName]' => 'Renamed Person',
        ]));
        $this->assertResponseRedirects('/settings/profile');
        $this->assertEmailCount(0);

        $em->clear();
        $fresh = $em->getRepository(User::class)->find($user->getId());
        self::assertSame('Renamed Person', $fresh->getDisplayName());
        self::assertSame('name@test.local', $fresh->getEmail());
    }

    public function testEmailChangeIsPasswordGatedAndUniquenessChecked(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'gated@test.local');
        $this->seedUser($em, 'taken@test.local');
        $client->loginUser($user);

        // Wrong password → nothing saved, nothing sent.
        $crawler = $client->request('GET', '/settings/profile');
        $client->submit($crawler->selectButton('Save Changes')->form([
            'profile_form[email]' => 'new@test.local',
            'profile_form[current_password]' => 'not-the-password',
        ]));
        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString("That password isn't right.", $client->getCrawler()->html());
        $this->assertEmailCount(0);

        // Right password, taken address → rejected.
        $crawler = $client->request('GET', '/settings/profile');
        $client->submit($crawler->selectButton('Save Changes')->form([
            'profile_form[email]' => 'taken@test.local',
            'profile_form[current_password]' => 'password123',
        ]));
        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('already in use', $client->getCrawler()->html());
        $this->assertEmailCount(0);

        $em->clear();
        self::assertSame('gated@test.local', $em->getRepository(User::class)->find($user->getId())->getEmail());
    }

    public function testConfirmFirstRoundTripAppliesVerifiesAndNotifiesOldAddress(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'old@test.local');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/settings/profile');
        $client->submit($crawler->selectButton('Save Changes')->form([
            'profile_form[email]' => 'shiny-new@test.local',
            'profile_form[current_password]' => 'password123',
        ]));
        $this->assertResponseRedirects('/settings/profile');

        // The confirmation went to the NEW address; the account is untouched.
        $this->assertEmailCount(1);
        $message = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('shiny-new@test.local', $message->getTo()[0]->getAddress());
        $em->clear();
        self::assertSame('old@test.local', $em->getRepository(User::class)->find($user->getId())->getEmail());

        // Follow the signed link → change applies, address is verified, and the
        // OLD inbox gets the "if this wasn't you" notice.
        $confirmUrl = $this->extractLink((string) $message->getHtmlBody());
        $client->request('GET', $confirmUrl);
        $this->assertResponseRedirects('/settings/profile');
        $this->assertEmailCount(1);
        $notice = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $notice);
        self::assertSame('old@test.local', $notice->getTo()[0]->getAddress());

        $em->clear();
        $fresh = $em->getRepository(User::class)->find($user->getId());
        self::assertSame('shiny-new@test.local', $fresh->getEmail());
        self::assertNotNull($fresh->getEmailVerifiedAt(), 'clicking the link IS inbox proof for the new address');
        self::assertStringContainsString(
            'shiny-new@test.local',
            $client->followRedirect()->html(),
            'success flash names the new address',
        );
    }

    public function testTamperedOrForeignLinkIsRefused(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'victim@test.local');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/settings/profile');
        $client->submit($crawler->selectButton('Save Changes')->form([
            'profile_form[email]' => 'intended@test.local',
            'profile_form[current_password]' => 'password123',
        ]));
        $message = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        $confirmUrl = $this->extractLink((string) $message->getHtmlBody());

        // Tampering with the target address invalidates the signature.
        $client->request('GET', str_replace('intended%40test.local', 'attacker%40test.local', $confirmUrl));
        $this->assertResponseRedirects('/settings/profile');
        self::assertStringContainsString('invalid or has expired', $client->followRedirect()->html());

        // A different signed-in account can't consume someone else's link.
        $client->loginUser($this->seedUser($em, 'other@test.local'));
        $client->request('GET', $confirmUrl);
        $this->assertResponseRedirects('/settings/profile');
        self::assertStringContainsString('different account', $client->followRedirect()->html());

        $em->clear();
        self::assertSame('victim@test.local', $em->getRepository(User::class)->find($user->getId())->getEmail());
    }

    public function testOverlongAddressFailsInTheFormNotAtTheDbWrite(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'short@test.local');
        $client->loginUser($user);

        // Syntactically valid but past the entity column's 180 chars — must die
        // as a form error, not at the DB write after a confirmation was mailed.
        $overlong = str_repeat('a', 175) . '@test.local';
        self::assertGreaterThan(180, strlen($overlong));

        $crawler = $client->request('GET', '/settings/profile');
        $client->submit($crawler->selectButton('Save Changes')->form([
            'profile_form[email]' => $overlong,
            'profile_form[current_password]' => 'password123',
        ]));
        $this->assertResponseStatusCodeSame(422);
        $this->assertEmailCount(0);

        $em->clear();
        self::assertSame('short@test.local', $em->getRepository(User::class)->find($user->getId())->getEmail());
    }

    public function testMailTransportFailureFlashesARetryInsteadOf500(): void
    {
        $client = static::createClient();
        // Keep one kernel across requests so the throwing mailer double stays
        // in the container for the POST.
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'transport@test.local');

        static::getContainer()->set('mailer.mailer', new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('smarthost unreachable');
            }
        });

        $client->loginUser($user);
        $crawler = $client->request('GET', '/settings/profile');
        $client->submit($crawler->selectButton('Save Changes')->form([
            'profile_form[email]' => 'unreachable@test.local',
            'profile_form[current_password]' => 'password123',
        ]));

        // Caught → retryable flash, never a 500; nothing changed on the account.
        $this->assertResponseRedirects('/settings/profile');
        self::assertStringContainsString(
            "couldn't send the confirmation email",
            $client->followRedirect()->html(),
        );
        $em->clear();
        self::assertSame('transport@test.local', $em->getRepository(User::class)->find($user->getId())->getEmail());
    }

    public function testStaleLinkFromASupersededRequestIsRefused(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'original@test.local');
        $client->loginUser($user);

        // Two outstanding requests: the typo'd first try, then the correction.
        $submitChange = function (string $to) use ($client): string {
            $crawler = $client->request('GET', '/settings/profile');
            $client->submit($crawler->selectButton('Save Changes')->form([
                'profile_form[email]' => $to,
                'profile_form[current_password]' => 'password123',
            ]));
            $message = $this->getMailerMessage();
            self::assertInstanceOf(Email::class, $message);

            return $this->extractLink((string) $message->getHtmlBody());
        };
        $staleLink = $submitChange('first-try@test.local');
        $freshLink = $submitChange('second-try@test.local');

        // Consuming the fresh link lands the change…
        $client->request('GET', $freshLink);
        $em->clear();
        self::assertSame('second-try@test.local', $em->getRepository(User::class)->find($user->getId())->getEmail());

        // …which kills the superseded one: links are bound to the address they
        // were issued against, so there's no 24 h replay window in which the
        // typo'd first try can flip the account back.
        $client->request('GET', $staleLink);
        $this->assertResponseRedirects('/settings/profile');
        self::assertStringContainsString('invalid or has expired', $client->followRedirect()->html());
        $em->clear();
        self::assertSame('second-try@test.local', $em->getRepository(User::class)->find($user->getId())->getEmail());
    }

    public function testDeliverabilityWarningFiresOnTheChangeFormThenAckAccepts(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'warned@test.local');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/settings/profile');
        $client->submit($crawler->selectButton('Save Changes')->form([
            'profile_form[email]' => 'me@gmial.com',
            'profile_form[current_password]' => 'password123',
        ]));
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('looks like a misspelling', $client->getCrawler()->html());
        $this->assertEmailCount(0);

        // The user insists — the resubmit (hidden ack riding along) sends the
        // confirmation to the warned address.
        $client->submit($client->getCrawler()->selectButton('Save Changes')->form([
            'profile_form[email]' => 'me@gmial.com',
            'profile_form[current_password]' => 'password123',
        ]));
        $this->assertResponseRedirects('/settings/profile');
        $this->assertEmailCount(1);
    }

    public function testHubListsTheProfilePage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'hub@test.local'));

        $crawler = $client->request('GET', '/settings');
        self::assertSame(1, $crawler->filter('a[href="/settings/profile"]')->count());
    }

    private function extractLink(string $html): string
    {
        self::assertSame(1, preg_match('/href="([^"]+confirm-email[^"]+)"/', $html, $m), 'confirm link present');

        return html_entity_decode($m[1]);
    }

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Profile Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        // The change-email limiter's storage outlives truncated DB rows, and user
        // ids repeat across tests — reset the seeded user's window (the
        // EmailVerificationTest idiom) so reruns can't 429 unrelated cases.
        static::getContainer()->get('limiter.email_verification')
            ->create('email-change-user-' . $user->getId())->reset();

        return $user;
    }
}

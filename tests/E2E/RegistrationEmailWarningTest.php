<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Tests\Stub\StubEmailDeliverabilityChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The warn-don't-block email deliverability check on /register (in-process,
 * real DB; DNS answered by StubEmailDeliverabilityChecker — no network). The
 * contract: a suspicious address warns exactly once, an unchanged resubmit is
 * accepted, and an edited address re-arms the check.
 */
final class RegistrationEmailWarningTest extends WebTestCase
{
    use DbResetTrait;

    private const FIELDS = [
        'registration_form[displayName]' => 'Typo Prone',
        'registration_form[plainPassword][first]' => 'password123',
        'registration_form[plainPassword][second]' => 'password123',
        'registration_form[invitationCode]' => '',
    ];

    public function testTypoDomainWarnsWithSuggestionThenAcceptsTheResubmit(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $client->request('GET', '/register');
        $client->submitForm('Create Account', self::FIELDS + [
            'registration_form[email]' => 'grandma@gmial.com',
        ]);

        // Warned, not blocked: same page, no account yet, suggestion offered,
        // and the hidden ack carries the warned address.
        $this->assertResponseIsSuccessful();
        $html = $client->getCrawler()->html();
        self::assertStringContainsString('looks like a misspelling', $html);
        self::assertStringContainsString('grandma@gmail.com', $html, 'one-click suggestion preserves the local part');
        self::assertSame(
            'grandma@gmial.com',
            $client->getCrawler()->filter('input[name="email_warning_ack"]')->attr('value'),
        );
        $em->clear();
        self::assertNull($em->getRepository(User::class)->findOneBy(['email' => 'grandma@gmial.com']));

        // The user insists — an unchanged resubmit (ack riding along in the
        // re-rendered form) creates the account. Warn once, never nag. The
        // passwords are deliberately re-posted here: PasswordType clears on
        // any re-render (same as the invalid-coupon path), and the warning
        // copy tells the user to re-enter theirs.
        $client->submitForm('Create Account', self::FIELDS + [
            'registration_form[email]' => 'grandma@gmial.com',
        ]);
        $this->assertResponseRedirects('/');
        $em->clear();
        self::assertNotNull($em->getRepository(User::class)->findOneBy(['email' => 'grandma@gmial.com']));
    }

    public function testNoMailServerDomainWarnsAndEditedAddressReArmsTheCheck(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $client->request('GET', '/register');
        $client->submitForm('Create Account', self::FIELDS + [
            'registration_form[email]' => 'user@' . StubEmailDeliverabilityChecker::NO_MX_DOMAIN,
        ]);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString("couldn't find a mail server", $client->getCrawler()->html());

        // Editing to a DIFFERENT suspicious address must warn again — the ack
        // is per-address, not a blanket "stop checking me".
        $client->submitForm('Create Account', self::FIELDS + [
            'registration_form[email]' => 'other@' . StubEmailDeliverabilityChecker::NO_MX_DOMAIN,
        ]);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString("couldn't find a mail server", $client->getCrawler()->html());
        $em->clear();
        self::assertSame(0, count($em->getRepository(User::class)->findAll()), 'no account until acknowledged');
    }

    public function testHealthyAddressRegistersWithNoWarningDetour(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $client->request('GET', '/register');
        $client->submitForm('Create Account', self::FIELDS + [
            'registration_form[email]' => 'fine@gmail.com',
        ]);
        $this->assertResponseRedirects('/');
        $em->clear();
        self::assertNotNull($em->getRepository(User::class)->findOneBy(['email' => 'fine@gmail.com']));
    }
}

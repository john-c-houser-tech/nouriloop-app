<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Service\InvitationCodeManager;

/**
 * Registration is open (public soft-launch) but the beta-code machinery is intact:
 * an *invalid* code must be rejected, and a valid single-use code must create the
 * account. (No-code Free-tier signup + honeypot are covered in PublicRegistrationTest.)
 */
final class RegistrationTest extends E2ETestCase
{
    public function testRegistrationRejectedWithoutValidCode(): void
    {
        $client = self::createPantherClient();
        $client->request('GET', '/register');
        // The coupon field hides behind a disclosure link now — a real browser
        // can't type into a d-none input, so open it first (like a user would).
        $client->getCrawler()->filter('#coupon-toggle')->click();
        $client->submitForm('Create Account', [
            'registration_form[displayName]' => 'New Person',
            'registration_form[email]' => 'newbie@test.local',
            'registration_form[invitationCode]' => 'totally-invalid-code',
            'registration_form[plainPassword][first]' => self::PASSWORD,
            'registration_form[plainPassword][second]' => self::PASSWORD,
        ]);

        // Stays on the registration page; no account created.
        self::assertStringContainsString('/register', $client->getCurrentURL());

        $this->em->clear();
        self::assertNull(
            $this->em->getRepository(User::class)->findOneBy(['email' => 'newbie@test.local'])
        );
    }

    public function testEmailTypoSuggestionClickCorrectsTheFieldAndRegisters(): void
    {
        $client = self::createPantherClient();
        $client->request('GET', '/register');
        $client->submitForm('Create Account', [
            'registration_form[displayName]' => 'Typo Fixer',
            'registration_form[email]' => 'fixer@gmial.com',
            'registration_form[plainPassword][first]' => self::PASSWORD,
            'registration_form[plainPassword][second]' => self::PASSWORD,
        ]);

        // Warned re-render: the one-click correction fills the field and drops
        // both the warning and the acknowledgement (real-browser JS — the
        // in-process RegistrationEmailWarningTest can't exercise this).
        $client->waitForVisibility('#email-suggest', 5);
        $client->getCrawler()->filter('#email-suggest')->click();
        self::assertSame('fixer@gmail.com', (string) $client->executeScript(
            "return document.getElementById('registration_form_email').value;"
        ));
        self::assertSame(0, (int) $client->executeScript(
            "return document.querySelectorAll('#email-warning, input[name=\"email_warning_ack\"]').length;"
        ));

        // The corrected address sails through — passwords are re-entered, as
        // the warning copy instructs (the re-render clears password fields).
        $client->submitForm('Create Account', [
            'registration_form[plainPassword][first]' => self::PASSWORD,
            'registration_form[plainPassword][second]' => self::PASSWORD,
        ]);
        $client->wait(5)->until(
            static fn ($driver) => !str_contains($driver->getCurrentURL(), '/register'),
        );

        $this->em->clear();
        self::assertNotNull(
            $this->em->getRepository(User::class)->findOneBy(['email' => 'fixer@gmail.com']),
            'the corrected address registered',
        );
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'fixer@gmial.com']));
    }

    public function testLivePasswordFeedbackReactsWhileTyping(): void
    {
        $client = self::createPantherClient();
        $client->request('GET', '/register');

        // Too short → red length hint appears.
        $client->getCrawler()->filter('#registration_form_plainPassword_first')->sendKeys('short');
        $client->waitForVisibility('#pw-length-hint', 5);
        self::assertStringContainsString('text-danger', (string) $client->executeScript(
            "return document.getElementById('pw-length-hint').className;"
        ));

        // Long enough → the same hint flips green.
        $client->getCrawler()->filter('#registration_form_plainPassword_first')->sendKeys('-now-long-enough');
        self::assertStringContainsString('text-success', (string) $client->executeScript(
            "return document.getElementById('pw-length-hint').className;"
        ));

        // Mismatched confirm → red match hint.
        $client->getCrawler()->filter('#registration_form_plainPassword_second')->sendKeys('different');
        $client->waitForVisibility('#pw-match-hint', 5);
        self::assertStringContainsString('text-danger', (string) $client->executeScript(
            "return document.getElementById('pw-match-hint').className;"
        ));
    }

    public function testRegistrationSucceedsWithValidCode(): void
    {
        // Mint a real invitation code (created by an admin).
        $admin = $this->seedUser(self::ADMIN_EMAIL, ['ROLE_USER', 'ROLE_ADMIN']);
        $manager = self::getContainer()->get(InvitationCodeManager::class);
        $code = $manager->create($admin);
        $this->em->flush();
        $codeValue = $code->getCode();

        $client = self::createPantherClient();
        $client->request('GET', '/register');

        // The register page's inline JS fills the hidden timezone field from the
        // browser's Intl zone (regression guard: this was silently dead while it
        // was wired as a Stimulus controller — Stimulus never loads in this app,
        // and attr.id doesn't override a form widget's id).
        $browserTz = (string) $client->executeScript(
            'return Intl.DateTimeFormat().resolvedOptions().timeZone;'
        );
        self::assertSame(
            $browserTz,
            (string) $client->executeScript(
                "return document.getElementById('register-timezone').value;"
            ),
            'the inline JS must have filled the hidden timezone field',
        );

        // Open the coupon disclosure so the field is typeable in a real browser.
        $client->getCrawler()->filter('#coupon-toggle')->click();
        $client->submitForm('Create Account', [
            'registration_form[displayName]' => 'Invited Person',
            'registration_form[email]' => 'invited@test.local',
            'registration_form[invitationCode]' => $codeValue,
            'registration_form[plainPassword][first]' => self::PASSWORD,
            'registration_form[plainPassword][second]' => self::PASSWORD,
        ]);
        // Success auto-logs the user in and lands on the dashboard with a welcome
        // flash; wait for it so the row is committed before we query (the DB read
        // must not race the redirect).
        $client->waitForVisibility('.alert-success', 10);
        self::assertStringNotContainsString('/register', $client->getCurrentURL());
        self::assertStringNotContainsString('/login', $client->getCurrentURL());

        // Account now exists — with the browser's real zone, not the LA default.
        $this->em->clear();
        $created = $this->em->getRepository(User::class)->findOneBy(['email' => 'invited@test.local']);
        self::assertNotNull($created);
        self::assertSame($browserTz, $created->getTimezone());
    }
}

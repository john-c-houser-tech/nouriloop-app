<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The public "Send us feedback" form (linked from the footer) emails the team.
 *
 * In-process (KernelBrowser) so the mailer message logger can be asserted; lives
 * under tests/E2E because it boots the kernel (the unit CI job is DB-less). Turnstile
 * is a no-op in the test env (no keys), so no CAPTCHA token is needed.
 */
final class FeedbackTest extends WebTestCase
{
    public function testFeedbackPageIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/feedback');

        $this->assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Send us feedback');
    }

    public function testSubmittingFeedbackEmailsTheTeamWithReplyTo(): void
    {
        $client = static::createClient();

        $client->request('GET', '/feedback');
        $client->submitForm('Send feedback', [
            'feedback_form[name]' => 'Casey Tester',
            'feedback_form[email]' => 'casey@example.test',
            'feedback_form[message]' => 'Love the app — the glucose curves are great.',
        ]);

        $this->assertResponseRedirects('/feedback/sent');

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertNotNull($email);
        self::assertSame('NouriLoop feedback', $email->getSubject());
        $this->assertEmailAddressContains($email, 'To', 'info@nouriloop.com');
        // Submitter's address becomes Reply-To so a reply goes straight back to them.
        $this->assertEmailAddressContains($email, 'Reply-To', 'casey@example.test');
        // ...and only them: the global MAILER_REPLY_TO is a default, not an addition.
        $this->assertEmailAddressNotContains($email, 'Reply-To', 'noreply@nouriloop.com');
        $this->assertEmailHtmlBodyContains($email, 'Love the app');
        $this->assertEmailHtmlBodyContains($email, 'Casey Tester');
    }

    public function testAnonymousFeedbackWithoutEmailStillSends(): void
    {
        $client = static::createClient();

        $client->request('GET', '/feedback');
        $client->submitForm('Send feedback', [
            'feedback_form[name]' => '',
            'feedback_form[email]' => '',
            'feedback_form[message]' => 'Just saying hi, no reply needed.',
        ]);

        $this->assertResponseRedirects('/feedback/sent');
        $this->assertEmailCount(1);
        $this->assertEmailHtmlBodyContains($this->getMailerMessage(), '(not given)');
    }

    public function testHoneypotSubmissionSendsNothing(): void
    {
        $client = static::createClient();

        $client->request('GET', '/feedback');
        $client->submitForm('Send feedback', [
            'feedback_form[message]' => 'spammy spam',
            'feedback_form[website]' => 'http://spam.example',
        ]);

        // Looks successful to the bot, but no mail is sent.
        $this->assertResponseRedirects('/feedback/sent');
        $this->assertEmailCount(0);
    }

    public function testBlankFeedbackIsRejected(): void
    {
        $client = static::createClient();

        $client->request('GET', '/feedback');
        $client->submitForm('Send feedback', [
            'feedback_form[message]' => '',
        ]);

        // Symfony re-renders an invalid form submission with 422.
        $this->assertResponseStatusCodeSame(422);
        $this->assertEmailCount(0);
    }
}

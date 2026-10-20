<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * The business site's contact form (nouriloop.com posts to POST /site-contact). In-process
 * WebTestCase with the mailer message logger. Every outcome redirects back to a static page
 * on SITE_CONTACT_ORIGIN; covers the send, the honeypot, validation, another site's Origin
 * and the per-IP cap.
 */
final class SiteContactTest extends WebTestCase
{
    private const SITE = 'https://nouriloop.com';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get('limiter.site_contact')
            ->create('site-contact-ip-127.0.0.1')->reset();
    }

    public function testAMessageIsMailedToInfoWithTheVisitorAsReplyTo(): void
    {
        $this->post([
            'name' => 'Pat Example',
            'email' => 'pat@example.org',
            'message' => "Can we talk about AI training\nfor our team?",
        ]);

        $this->assertResponseRedirects(self::SITE . '/contact/thanks/', 303);
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('info@nouriloop.com', $email->getTo()[0]->getAddress());
        // Only the visitor: the global MAILER_REPLY_TO default must not be added (Kernel::process).
        self::assertSame(['pat@example.org'], array_map(fn ($a) => $a->getAddress(), $email->getReplyTo()));
        self::assertSame('Business site: Pat Example', $email->getSubject());
        self::assertStringContainsString('for our team?', (string) $email->getHtmlBody());
    }

    public function testTheHoneypotLooksSuccessfulButSendsNothing(): void
    {
        $this->post(['email' => 'bot@example.org', 'message' => 'Buy now', 'website' => 'http://spam']);

        $this->assertResponseRedirects(self::SITE . '/contact/thanks/', 303);
        $this->assertEmailCount(0);
    }

    public function testAMissingOrBadAddressGoesToTheProblemPage(): void
    {
        $this->post(['email' => 'not-an-address', 'message' => 'Hello']);
        $this->assertResponseRedirects(self::SITE . '/contact/problem/?reason=invalid', 303);

        $this->post(['email' => 'pat@example.org', 'message' => '   ']);
        $this->assertResponseRedirects(self::SITE . '/contact/problem/?reason=invalid', 303);
        $this->assertEmailCount(0);
    }

    public function testAnotherSitesOriginIsRefused(): void
    {
        $this->post(['email' => 'pat@example.org', 'message' => 'Hello'], 'https://elsewhere.example');

        $this->assertResponseStatusCodeSame(403);
        $this->assertEmailCount(0);
    }

    /** A sandboxed iframe without allow-same-origin posts with an opaque Origin. */
    public function testAnOpaqueNullOriginIsRefused(): void
    {
        $this->post(['email' => 'pat@example.org', 'message' => 'Hello'], 'null');

        $this->assertResponseStatusCodeSame(403);
        $this->assertEmailCount(0);
    }

    public function testASixthMessageInAnHourIsTurnedAway(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->post(['email' => 'pat@example.org', 'message' => 'Hello ' . $i]);
            $this->assertResponseRedirects(self::SITE . '/contact/thanks/', 303);
        }

        $this->post(['email' => 'pat@example.org', 'message' => 'One more']);
        $this->assertResponseRedirects(self::SITE . '/contact/problem/?reason=limited', 303);
        $this->assertEmailCount(0);
    }

    public function testOnlyPostIsAnswered(): void
    {
        $this->client->request('GET', '/site-contact');

        $this->assertResponseStatusCodeSame(405);
    }

    public function testASelfHostedCopyHasNoSuchRoute(): void
    {
        $saved = $_ENV['APP_HOSTING_MODE'] ?? null;
        $_ENV['APP_HOSTING_MODE'] = $_SERVER['APP_HOSTING_MODE'] = 'self';
        try {
            static::ensureKernelShutdown();
            $this->client = static::createClient();
            $this->post(['email' => 'pat@example.org', 'message' => 'Hello']);

            $this->assertResponseStatusCodeSame(404);
            $this->assertEmailCount(0);
        } finally {
            if ($saved === null) {
                unset($_ENV['APP_HOSTING_MODE'], $_SERVER['APP_HOSTING_MODE']);
            } else {
                $_ENV['APP_HOSTING_MODE'] = $_SERVER['APP_HOSTING_MODE'] = $saved;
            }
        }
    }

    /** @param array<string, string> $fields */
    private function post(array $fields, string $origin = self::SITE): void
    {
        $this->client->request('POST', '/site-contact', $fields, [], ['HTTP_ORIGIN' => $origin]);
    }
}

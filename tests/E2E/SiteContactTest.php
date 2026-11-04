<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Service\TurnstileValidator;
use App\SiteContact\FormToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * The business site's contact form (nouriloop.com posts to POST /site-contact). In-process
 * WebTestCase with the mailer message logger. Every outcome redirects back to a static page
 * on SITE_CONTACT_ORIGIN; covers the send, the honeypot, the signed form token, Turnstile,
 * validation, another site's Origin and the per-IP cap.
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
        $this->post(['email' => 'bot@example.org', 'message' => 'Buy now', 'website' => 'http://spam'], self::SITE, null);

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

    public function testAPostWithoutAFormTokenIsSentBackToTheProblemPage(): void
    {
        $this->post(['email' => 'pat@example.org', 'message' => 'Hello'], self::SITE, null);

        $this->assertResponseRedirects(self::SITE . '/contact/problem/?reason=check', 303);
        $this->assertEmailCount(0);
    }

    public function testATokenFromTheSameSecondOrAForgedOneIsRefused(): void
    {
        $formToken = static::getContainer()->get(FormToken::class);

        $this->post(['email' => 'pat@example.org', 'message' => 'Hello'], self::SITE, $formToken->issue());
        $this->assertResponseRedirects(self::SITE . '/contact/problem/?reason=check', 303);

        $this->post(['email' => 'pat@example.org', 'message' => 'Hello'], self::SITE, (time() - 60) . '.forged');
        $this->assertResponseRedirects(self::SITE . '/contact/problem/?reason=check', 303);

        $this->post(['email' => 'pat@example.org', 'message' => 'Hello'], self::SITE, $formToken->issue(time() - FormToken::MAX_AGE_SECONDS - 5));
        $this->assertResponseRedirects(self::SITE . '/contact/problem/?reason=check', 303);
        $this->assertEmailCount(0);
    }

    public function testTheTokenEndpointGivesATokenThatThenWorksOnlyFromTheSiteOrigin(): void
    {
        $this->client->request('GET', '/site-contact/token', [], [], ['HTTP_ORIGIN' => self::SITE]);

        $this->assertResponseIsSuccessful();
        self::assertSame(self::SITE, $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        // No Turnstile keys in the test env, so no site key is handed out and no widget shown.
        self::assertSame('', $body['turnstileSiteKey']);
        $token = $body['token'];
        // Just issued, so too young to post: the page's reader needs a few seconds.
        self::assertFalse(static::getContainer()->get(FormToken::class)->isValid($token));
        self::assertTrue(static::getContainer()->get(FormToken::class)->isValid($token, time() + FormToken::MIN_AGE_SECONDS));
    }

    public function testTurnstileMustPassWhenItIsSwitchedOn(): void
    {
        // Keep the stub below alive across requests (the browser reboots the kernel by default).
        $this->client->disableReboot();
        static::getContainer()->set(TurnstileValidator::class, new class extends TurnstileValidator {
            public function __construct()
            {
            }

            public function siteKey(): string
            {
                return 'site-key';
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function verify(?string $token, ?string $ip = null): bool
            {
                return $token === 'good-token';
            }
        });

        $this->client->request('GET', '/site-contact/token', [], [], ['HTTP_ORIGIN' => self::SITE]);
        self::assertSame('site-key', json_decode((string) $this->client->getResponse()->getContent(), true)['turnstileSiteKey']);

        $this->post(['email' => 'pat@example.org', 'message' => 'Hello']);
        $this->assertResponseRedirects(self::SITE . '/contact/problem/?reason=check', 303);
        $this->assertEmailCount(0);

        $this->post(['email' => 'pat@example.org', 'message' => 'Hello', 'cf-turnstile-response' => 'good-token']);
        $this->assertResponseRedirects(self::SITE . '/contact/thanks/', 303);
        $this->assertEmailCount(1);
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

    /**
     * Posts like the real page: with a form token issued long enough ago, unless one is given
     * (or null for none).
     *
     * @param array<string, string> $fields
     */
    private function post(array $fields, string $origin = self::SITE, ?string $token = 'default'): void
    {
        if ($token === 'default') {
            $token = static::getContainer()->get(FormToken::class)->issue(time() - 30);
        }
        if ($token !== null) {
            $fields['ft'] = $token;
        }
        $this->client->request('POST', '/site-contact', $fields, [], ['HTTP_ORIGIN' => $origin]);
    }
}

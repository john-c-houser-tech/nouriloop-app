<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The RFC 9116 /.well-known/security.txt: served as plain text, readable ANONYMOUSLY,
 * carrying a working contact address and an Expires that has not lapsed.
 *
 * In-process WebTestCase (no DB needed — the body is static), kept under tests/E2E so it
 * runs in the e2e CI job alongside the other in-process functional tests, matching
 * AboutPageTest.
 *
 * ⚠️ testExpiresIsNotAboutToLapse() is the point of this file. RFC 9116 treats the
 * document as stale past Expires, and an expired security.txt signals an abandoned
 * project — worse than publishing nothing. Nobody notices a text file quietly going out
 * of date, so this turns that into a red build 30 days ahead of time.
 */
final class SecurityTxtTest extends WebTestCase
{
    private const PATH = '/.well-known/security.txt';

    /** Days of warning before Expires that CI starts failing. */
    private const RENEWAL_WARNING_DAYS = 30;

    public function testIsServedAnonymouslyAsPlainText(): void
    {
        $client = static::createClient();
        $client->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function testCarriesTheRequiredRfc9116Fields(): void
    {
        $client = static::createClient();
        $client->request('GET', self::PATH);

        $body = (string) $client->getResponse()->getContent();

        // Contact and Expires are the only two fields RFC 9116 requires.
        self::assertMatchesRegularExpression('/^Contact: mailto:\S+@\S+$/m', $body);
        self::assertMatchesRegularExpression('/^Expires: \S+$/m', $body);
        self::assertStringContainsString('Contact: mailto:info@nouriloop.com', $body);
    }

    public function testCanonicalPointsAtThisVeryFile(): void
    {
        $client = static::createClient();
        $client->request('GET', self::PATH);

        $body = (string) $client->getResponse()->getContent();

        self::assertMatchesRegularExpression(
            '#^Canonical: https?://\S+' . preg_quote(self::PATH, '#') . '$#m',
            $body,
            'Canonical must name the URL this file is actually served from.',
        );
    }

    /**
     * Policy: links SECURITY.md in the public nouriloop-app mirror. A 404 there would be
     * worse than no Policy: at all, so keep this URL in step with the mirror's location.
     */
    public function testPolicyPointsAtThePublicSecurityMd(): void
    {
        $client = static::createClient();
        $client->request('GET', self::PATH);

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(
            'Policy: https://github.com/john-c-houser-tech/nouriloop-app/blob/main/SECURITY.md',
            $body,
        );
    }

    /**
     * The researcher who finds this file may read nothing else we've written, and the
     * linked SECURITY.md is only as current as the mirror's last re-export. So the rules
     * that keep a well-meaning report from becoming an incident travel in the file itself.
     */
    public function testCarriesTheTestingRulesInline(): void
    {
        $client = static::createClient();
        $client->request('GET', self::PATH);

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('your own account only', $body);
        self::assertStringContainsString("another user's data", $body);
        self::assertStringContainsString('minimum proof', $body);
        self::assertStringContainsString('no bug bounty', $body);
    }

    /**
     * ⚠️ If this test just failed, the fix is NOT to widen the window.
     *
     * Open src/Controller/SecurityTxtController.php, push EXPIRES out about a year, and
     * while you are there confirm the Contact address still reaches a human. That
     * two-minute check is the entire annual review of this file, and this failing test
     * is the only thing that will ever prompt it.
     */
    public function testExpiresIsNotAboutToLapse(): void
    {
        $client = static::createClient();
        $client->request('GET', self::PATH);

        $body = (string) $client->getResponse()->getContent();

        self::assertSame(1, preg_match('/^Expires: (\S+)$/m', $body, $m), 'No Expires field found.');

        $expires = new \DateTimeImmutable($m[1]);
        $deadline = new \DateTimeImmutable(sprintf('+%d days', self::RENEWAL_WARNING_DAYS));

        self::assertGreaterThan(
            $deadline,
            $expires,
            sprintf(
                'security.txt Expires (%s) is within %d days. RFC 9116 consumers treat a lapsed '
                . 'file as stale, and an expired security contact reads as an abandoned project — '
                . 'worse than publishing none. Bump EXPIRES in SecurityTxtController and re-check '
                . 'that %s still reaches someone.',
                $expires->format(\DATE_ATOM),
                self::RENEWAL_WARNING_DAYS,
                'info@nouriloop.com',
            ),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\SiteContact;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A signed "this form was opened at time T" token for the business-site contact form.
 *
 * The form lives on a static site, so it cannot carry a server-rendered CSRF token or a
 * session. Instead the page fetches a token from GET /site-contact/token with JavaScript and
 * posts it back. A bot that posts the HTML form without running its script has no token; one
 * that replays a token too soon, too late or with a changed timestamp is refused. The token is
 * only an HMAC over the timestamp, so it proves the page was opened at that time, nothing more.
 */
class FormToken
{
    /** Quicker than this and a person cannot have read and filled in the form. */
    public const MIN_AGE_SECONDS = 3;

    /** A form left open longer than this is stale; the visitor reloads the page. */
    public const MAX_AGE_SECONDS = 7200;

    public function __construct(#[Autowire('%kernel.secret%')] private readonly string $secret)
    {
    }

    public function issue(?int $now = null): string
    {
        $issued = (string) ($now ?? time());

        return $issued . '.' . $this->sign($issued);
    }

    public function isValid(string $token, ?int $now = null): bool
    {
        $parts = explode('.', $token, 2);
        if (\count($parts) !== 2 || !ctype_digit($parts[0])) {
            return false;
        }
        if (!hash_equals($this->sign($parts[0]), $parts[1])) {
            return false;
        }

        $age = ($now ?? time()) - (int) $parts[0];

        return $age >= self::MIN_AGE_SECONDS && $age <= self::MAX_AGE_SECONDS;
    }

    private function sign(string $issued): string
    {
        return hash_hmac('sha256', 'site-contact:' . $issued, $this->secret);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\EmailDeliverabilityWarning;

/**
 * Warn-only deliverability check for user-typed email addresses (registration,
 * and the Settings ▸ Profile email change). Two independent signals:
 *
 *  1. a known-misspelling domain map (gmial.com → gmail.com …) — checked FIRST,
 *     because typo-squatted domains often have real MX records; and
 *  2. a DNS MX/A lookup — no mail server at all means the address is
 *     undeliverable (wrong-mailbox-at-a-real-domain typos are invisible here;
 *     that's what the change-email flow is for).
 *
 * Deliberately NOT a validator: it must never block a signup (John's call
 * 2026-07-24 — warn, let the user resubmit to confirm). ⚠️ Known limitation
 * (accepted, Copilot flag on PR #178): PHP's checkdnsrr() returns false for
 * BOTH "no such records" and a transient resolver failure, so a resolver
 * outage would warn every registrant during the outage. That is precisely why
 * this is warn-only — the worst cost is one extra confirming resubmit, never a
 * blocked signup. (Distinguishing NXDOMAIN from SERVFAIL needs a raw DNS
 * client; not worth a dependency for a soft hint.) The catch below covers the
 * throwing failure modes (e.g. invalid host input). Not final + protected DNS
 * hook so the test env can substitute a deterministic stub (no network in
 * tests — config/services_test.yaml).
 */
class EmailDeliverabilityChecker
{
    /**
     * Conservative, well-known misspellings only — a wrong suggestion is worse
     * than no suggestion. Keys and values are full domains, lowercase.
     */
    private const TYPO_DOMAINS = [
        'gmial.com'    => 'gmail.com',
        'gamil.com'    => 'gmail.com',
        'gmal.com'     => 'gmail.com',
        'gmali.com'    => 'gmail.com',
        'gmil.com'     => 'gmail.com',
        'gnail.com'    => 'gmail.com',
        'gmaill.com'   => 'gmail.com',
        'gmail.co'     => 'gmail.com',
        'gmail.cm'     => 'gmail.com',
        'gmail.con'    => 'gmail.com',
        'gmail.comm'   => 'gmail.com',
        'hotmial.com'  => 'hotmail.com',
        'hotmal.com'   => 'hotmail.com',
        'hotmali.com'  => 'hotmail.com',
        'hotmail.co'   => 'hotmail.com',
        'hotmail.cm'   => 'hotmail.com',
        'hotmail.con'  => 'hotmail.com',
        'yaho.com'     => 'yahoo.com',
        'yahooo.com'   => 'yahoo.com',
        'yhoo.com'     => 'yahoo.com',
        'yahoo.cm'     => 'yahoo.com',
        'yahoo.con'    => 'yahoo.com',
        'outlok.com'   => 'outlook.com',
        'outllook.com' => 'outlook.com',
        'outlook.co'   => 'outlook.com',
        'outlook.con'  => 'outlook.com',
        'iclod.com'    => 'icloud.com',
        'icoud.com'    => 'icloud.com',
        'iclould.com'  => 'icloud.com',
        'icloud.co'    => 'icloud.com',
        'icloud.con'   => 'icloud.com',
        'aol.con'      => 'aol.com',
        'aol.cm'       => 'aol.com',
        'comcast.nte'  => 'comcast.net',
        'comcast.ent'  => 'comcast.net',
    ];

    /**
     * Null = nothing suspicious (or nothing checkable — syntax is the Email
     * constraint's job, not ours).
     */
    public function assess(string $email): ?EmailDeliverabilityWarning
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === strlen($email) - 1) {
            return null;
        }

        $local  = substr($email, 0, $at);
        $domain = strtolower(trim(substr($email, $at + 1)));
        if ($domain === '' || $local === '') {
            return null;
        }

        if (isset(self::TYPO_DOMAINS[$domain])) {
            return new EmailDeliverabilityWarning(
                'typo',
                $domain,
                $local . '@' . self::TYPO_DOMAINS[$domain],
            );
        }

        if (!$this->domainAccepts($domain)) {
            return new EmailDeliverabilityWarning('no_mx', $domain);
        }

        return null;
    }

    /**
     * True when the domain plausibly receives mail: an MX record, or (RFC 5321
     * fallback delivery) an A/AAAA record. Trailing dot pins the lookup as
     * absolute so resolver search domains can't produce false positives.
     * Overridden by the test stub — real DNS is banned in tests/CI. See the
     * class docblock for the accepted transient-failure limitation.
     */
    protected function domainAccepts(string $domain): bool
    {
        try {
            return checkdnsrr($domain . '.', 'MX')
                || checkdnsrr($domain . '.', 'A')
                || checkdnsrr($domain . '.', 'AAAA');
        } catch (\Throwable) {
            // Fail open on the throwing failure modes (bad host input). A
            // resolver-level failure returns false instead — indistinguishable
            // from "no records" (see class docblock), bounded by warn-only.
            return true;
        }
    }
}

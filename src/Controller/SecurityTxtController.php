<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Serves the RFC 9116 security.txt — the publicly discoverable way to report a
 * vulnerability in NouriLoop.
 *
 * ⚠️ This file exists because SECURITY.md CANNOT do this job. That repository is
 * private, so GitHub renders SECURITY.md only to people who already have access, and
 * private vulnerability reporting is unavailable on private repos. Until this route
 * shipped, an outside researcher looking at a service with open public registration and
 * live Stripe billing had no way to find a contact address at all. Deleting this route
 * puts that back — SECURITY.md is not a substitute, whatever it says.
 *
 * A controller, not a static public/ file, for the same reasons as RobotsController:
 * it passes through the firewall's PUBLIC_ACCESS rule, it is testable in WebTestCase,
 * and Canonical stays in sync with the real host across environments.
 *
 * The same privacy of the repo is why the comment block in the body carries the
 * essential testing rules rather than just linking to them: a researcher who reaches
 * this file can read nothing else we've written. A contact address with no rules
 * attached invites exactly the "I dumped your user table to prove it" report that the
 * policy exists to prevent.
 */
class SecurityTxtController extends AbstractController
{
    /**
     * RFC 9116 requires Expires, and treats the file as stale once it passes — a lapsed
     * security.txt reads as an abandoned project, which is worse than not publishing one.
     *
     * ⚠️ This is deliberately a fixed date rather than a rolling `now + 1 year`. A rolling
     * value can never expire, which defeats the point of the field (it exists so consumers
     * can detect an abandoned file), and it would also mean nothing ever prompts a review.
     * The staleness is instead made LOUD rather than silent: SecurityTxtTest fails once
     * fewer than 30 days remain, so CI goes red a month before researchers would see an
     * expired file. Bump this constant, confirm the contact address still reaches a human,
     * and the test goes green again — that check IS the annual review.
     *
     * This is the same principle as the daily digest being sent on quiet days: the alarm
     * has to fire on its own, because nobody goes looking for a file that stopped working.
     */
    private const EXPIRES = '2027-08-01T00:00:00Z';

    private const CONTACT = 'info@nouriloop.com';

    #[Route('/.well-known/security.txt', name: 'app_security_txt', methods: ['GET'])]
    public function index(): Response
    {
        $canonical = $this->generateUrl('app_security_txt', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $contact = self::CONTACT;
        $expires = self::EXPIRES;

        // Policy: is deliberately absent — it would have to point at SECURITY.md in a
        // private repo, and a 404 in a security.txt is worse than an omitted optional
        // field. If the policy is ever published at a public URL, add it here.
        $body = <<<SECURITYTXT
            # NouriLoop — security contact
            #
            # Thanks for looking. Reports are genuinely welcome and are read by the
            # developer directly. Please email the Contact address below, with
            # "Security:" at the start of the subject line, and include enough to
            # reproduce: URL or endpoint, steps, expected vs actual.
            #
            # Timelines (NouriLoop is built and run by one person, so these are what
            # can actually be honored, not an aspirational SLA):
            #   - acknowledgement of your report ....... 3 business days
            #   - initial assessment ................... 10 business days
            # There is no 24/7 on-call. If you believe user data is being actively
            # exposed RIGHT NOW, say so in the subject line — that changes priority.
            #
            # Please test responsibly. Real people's health and nutrition data is in
            # this system:
            #   - Test against your own account only. Registration is open and free.
            #   - Do not access, modify, download, or retain another user's data. If
            #     you hit someone else's data by accident, stop, don't keep it, and
            #     tell us in the report.
            #   - Use the minimum proof necessary. Showing that a record is readable
            #     is a finding; enumerating every record is an incident.
            #   - No denial of service, load testing, or heavy automated scanning.
            #
            # Out of scope: third-party services (Stripe, mail, hosting — report to
            # the vendor), social engineering, physical attacks, raw scanner output
            # with no demonstrated impact, and missing hardening headers with no
            # demonstrated exploit path.
            #
            # There is no bug bounty and no monetary reward. Credit in the release
            # notes on request. Declined reports get a reason, not silence.

            Contact: mailto:$contact
            Expires: $expires
            Preferred-Languages: en
            Canonical: $canonical

            SECURITYTXT;

        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}

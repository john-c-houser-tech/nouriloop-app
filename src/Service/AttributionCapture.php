<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * First-touch marketing attribution: stashes the campaign parameters an ad-fed
 * URL carries (`/lp/weight-loss?utm_campaign=google-weight-loss&gclid=…`) in the
 * session on the first hit that has any of them, and writes them onto the User
 * row at registration.
 *
 * Why first-party: Campaigner's conversion-integrity rules need a *count* of
 * registrations per campaign, and GA4 can't give one — it's consent-gated, and a
 * 45–70 audience declines cookie banners often enough that its figure is a floor,
 * not a count (see the Campaigner repo's CAMPAIGN-PLAN-EVALUATION-PLAN.md §4.2).
 * Our own database is exact.
 *
 * First touch, not last: the campaign that *found* the user is the one the ad
 * spend belongs to. Known limitation, deliberately not solved — someone who
 * clicks an ad, leaves, and returns directly days later lands in the session-less
 * bucket. At a handful of registrations a month that's noise; don't pre-build
 * multi-touch for it.
 */
final class AttributionCapture
{
    /**
     * The parameters we keep — exactly the ones the ad URLs carry. Deliberately
     * not utm_term (our campaigns don't set it) and deliberately not the referrer
     * or anything else that could drift toward a per-visitor profile.
     */
    public const PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'gclid'];

    /** Session key holding the first-touch values until registration. */
    public const SESSION_KEY = 'attribution';

    /** Column width; anything longer is a mangled or hostile URL, not a campaign name. */
    private const MAX_LENGTH = 255;

    /**
     * Stores the request's campaign parameters as this session's first touch.
     * No-ops when the URL carries none of them, and never overwrites an existing
     * capture (first touch wins). Only touches the session when there is
     * something to record, so an ordinary visitor doesn't get a session started
     * on their behalf.
     */
    public function captureFromRequest(Request $request): void
    {
        $values = self::readParams($request);
        if ($values === []) {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if ($session->has(self::SESSION_KEY)) {
            return;
        }

        $session->set(self::SESSION_KEY, $values);
    }

    /**
     * Copies the session's first-touch capture onto a user being registered.
     * Falls back to the current request's own parameters when there's no session
     * capture — covers a registration POST that itself carries the UTMs. Leaves
     * every column null when nothing was captured (direct/organic arrival).
     */
    public function applyTo(User $user, Request $request): void
    {
        $values = [];
        if ($request->hasSession() && $request->getSession()->has(self::SESSION_KEY)) {
            $stored = $request->getSession()->get(self::SESSION_KEY);
            $values = is_array($stored) ? $stored : [];
        }

        if ($values === []) {
            $values = self::readParams($request);
        }

        $user->setUtmSource($values['utm_source'] ?? null);
        $user->setUtmMedium($values['utm_medium'] ?? null);
        $user->setUtmCampaign($values['utm_campaign'] ?? null);
        $user->setUtmContent($values['utm_content'] ?? null);
        $user->setGclid($values['gclid'] ?? null);
    }

    /**
     * The request's campaign parameters, cleaned and bounded. Query-string values
     * are attacker-controllable, so control characters are stripped and the length
     * is capped before anything reaches the database (they're returned verbatim by
     * the counts endpoint).
     *
     * @return array<string, string> present, non-blank params only
     */
    private static function readParams(Request $request): array
    {
        $values = [];

        foreach (self::PARAMS as $param) {
            $raw = $request->query->get($param);
            if (!is_string($raw)) {
                continue;
            }

            $clean = trim(preg_replace('/[\p{C}]+/u', '', $raw) ?? '');
            if ($clean === '') {
                continue;
            }

            $values[$param] = mb_substr($clean, 0, self::MAX_LENGTH);
        }

        return $values;
    }
}

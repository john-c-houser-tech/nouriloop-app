<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only business metrics for the operator's own tooling (Campaigner's
 * conversion-integrity rules — see that repo's CAMPAIGN-PLAN-EVALUATION-PLAN.md
 * §4.2). Bearer-token auth on the stateless ^/api firewall, ROLE_ADMIN only:
 * these are whole-account figures, so an ordinary user's API token must not read
 * them.
 *
 * ⚠️ Counts only — never user identifiers, emails, or anything about an
 * individual. That is the whole privacy posture of this endpoint and it is not
 * an implementation detail to be relaxed later: the app deliberately keeps the
 * Meta Pixel off every authenticated page for the same reason. Do not let this
 * controller grow a per-user shape.
 */
#[Route('/api/metrics')]
class MetricsApiController extends AbstractController
{
    /** Window used when the caller passes no dates. */
    private const DEFAULT_DAYS = 30;

    /**
     * GET /api/metrics/registrations?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * Registrations in the window grouped by the campaign that first touched the
     * user, plus the window's total. Both bounds are inclusive whole days in the
     * server's frame (which is where `created_at` is written); `to` is expanded to
     * the end of that day internally. Defaults to the last 30 days ending today.
     *
     * Unattributed registrations come back as a `utm_campaign: null` bucket —
     * organic/direct arrivals and anyone whose first-touch session had expired.
     * It is a real number, not an error, and Campaigner should show it rather than
     * quietly assume the attributed slice is the whole.
     */
    #[Route('/registrations', name: 'api_metrics_registrations', methods: ['GET'])]
    public function registrations(Request $request, UserRepository $users): JsonResponse
    {
        // Belt and braces with the ^/api/metrics access_control rule: an admin-only
        // endpoint should say so in the code a reader is looking at.
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $today = new \DateTimeImmutable('today');

        try {
            $to = $this->parseDay($request->query->get('to')) ?? $today;
            $from = $this->parseDay($request->query->get('from'))
                ?? $to->modify(sprintf('-%d days', self::DEFAULT_DAYS - 1));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        if ($from > $to) {
            return $this->json(
                ['error' => '`from` must not be after `to`.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Half-open internally so a registration at 23:59 on the `to` day counts.
        $campaigns = $users->countRegistrationsByCampaign($from, $to->modify('+1 day'));

        return $this->json([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'total' => array_sum(array_column($campaigns, 'count')),
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * A `Y-m-d` query param as midnight local, or null when absent/blank.
     * Strict: a machine client sending junk should be told, not silently given a
     * different window than it asked for.
     *
     * @throws \InvalidArgumentException on an unparseable or non-calendar date
     */
    private function parseDay(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if ($parsed === false || $parsed->format('Y-m-d') !== trim($value)) {
            throw new \InvalidArgumentException('Dates must be in YYYY-MM-DD format.');
        }

        return $parsed;
    }
}

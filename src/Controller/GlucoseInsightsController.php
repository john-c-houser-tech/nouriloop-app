<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\GlucoseInsightsService;
use App\Service\SubscriptionService;
use App\Service\UserTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cross-meal glucose insights — the across-meals payoff of the per-meal response
 * cards. Deterministic rankings (no Claude); the AI "pattern coach" deep-dive is a
 * separate, higher-cost tier surfaced as a locked upsell when disabled.
 */
class GlucoseInsightsController extends AbstractController
{
    #[Route('/insights/glucose', name: 'app_glucose_insights')]
    public function index(
        Request $request,
        GlucoseInsightsService $insights,
        UserTime $userTime,
        SubscriptionService $subscriptions,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $days = (int) $request->query->get('days', 30);
        $days = in_array($days, [7, 14, 30, 90], true) ? $days : 30;

        // CGM↔meal correlation is a Loop+ feature. Free/Loop users get an upgrade
        // prompt instead of the rankings, so we skip the per-meal computation too.
        $cgmEnabled = $subscriptions->hasFeature($user, 'cgm_correlation');

        $from = $userTime->startOfDaysAgo($user, $days);
        $to   = $userTime->startOfTomorrow($user);

        return $this->render('insights/glucose.html.twig', [
            'days'       => $days,
            'cgmEnabled' => $cgmEnabled,
            'insights'   => $cgmEnabled ? $insights->forUser($user, $from, $to) : null,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CgmSetupStatus;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Service\ApiTokenManager;
use App\Service\CgmSetupStatusService;
use App\Service\OnboardingService;
use App\Service\SubscriptionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Connect your Lingo/Stelo" guided setup — one long page walking a user
 * through Health Auto Export installation, token creation, the REST-automation
 * config, and a live verification ladder (see CgmSetupStatusService). Available
 * to all tiers: importing glucose is free; the correlation features stay
 * gated to the paid plans where they already are.
 */
#[Route('/connect-cgm')]
class ConnectCgmController extends AbstractController
{
    public const TOKEN_NAME = 'Health Auto Export';

    #[Route('', name: 'app_connect_cgm', methods: ['GET'])]
    public function index(
        ApiTokenRepository $tokens,
        CgmSetupStatusService $statusService,
        SubscriptionService $subscriptions,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->render('connect_cgm/index.html.twig', [
            'tokens'     => $tokens->findByUserOrdered($user),
            'status'     => $statusService->check($user),
            'cgmEnabled' => $subscriptions->hasFeature($user, 'cgm_correlation'),
        ]);
    }

    #[Route('/token', name: 'app_connect_cgm_token', methods: ['POST'])]
    public function createToken(Request $request, ApiTokenManager $manager): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        if ($this->isCsrfTokenValid('connect-cgm-token', (string) $request->request->get('_token'))) {
            [, $plaintext] = $manager->create($user, self::TOKEN_NAME);

            // Same one-shot flash the Settings page uses: shown exactly once on
            // the next render (step 2's copy card + step 3's header value).
            $this->addFlash('api_token_new', $plaintext);
        }

        return $this->redirect($this->generateUrl('app_connect_cgm') . '#step-token');
    }

    /**
     * The verification ladder, polled by the wizard's inline JS every ~10 s.
     * No rate limiter: authenticated GET at 6 req/min/user over two indexed
     * queries — negligible. Reaching "dense" marks the onboarding health step —
     * an idempotent materialization of derived truth (markStep no-ops once
     * onboarding is resolved), same as the file importer marking it.
     */
    #[Route('/status', name: 'app_connect_cgm_status', methods: ['GET'])]
    public function status(CgmSetupStatusService $statusService, OnboardingService $onboarding): JsonResponse
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $status = $statusService->check($user);
        if ($status->state === CgmSetupStatus::STATE_DENSE) {
            $onboarding->markStep($user, 'health');
        }

        return new JsonResponse($status->toArray());
    }
}

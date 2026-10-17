<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\AllowanceTopUpService;
use App\Service\AnalysisAllowance;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Loop+'s monthly AI analyses (App Development Plan PR 7b, decision Q3): how many are
 * left, a one-off top-up for this month, or their own key for unlimited. Where a Loop+
 * user lands when the allowance runs out (AllowanceKind::Included). Hosted-only, like
 * every billing route; anyone without the Loop+ allowance is sent where their own
 * "more" comes from.
 */
#[IsGranted('ROLE_USER')]
final class AllowanceController extends AbstractController
{
    public function __construct(
        private readonly AnalysisAllowance $allowance,
        private readonly AllowanceTopUpService $topUps,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/allowance', name: 'app_allowance', methods: ['GET'], condition: "service('hosting').isHosted()")]
    public function index(): Response
    {
        $user = $this->user();
        $status = $this->allowance->status($user);
        if ($status === null || !$this->allowance->canTopUp($user)) {
            return $this->redirectToRoute($status?->kind->route() ?? 'app_settings_ai');
        }

        return $this->render('allowance/index.html.twig', ['allowance' => $status]);
    }

    #[Route(
        '/allowance/top-up',
        name: 'app_allowance_top_up',
        methods: ['POST'],
        condition: "service('hosting').isHosted()",
    )]
    public function topUp(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('allowance-top-up', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('allowance_page.flash.session_expired'));

            return $this->redirectToRoute('app_allowance');
        }

        $user = $this->user();
        if (!$this->allowance->canTopUp($user)) {
            return $this->redirectToRoute('app_allowance');
        }

        $successUrl = $this->generateUrl('app_allowance_top_up_success', [], UrlGeneratorInterface::ABSOLUTE_URL)
            . '?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $this->generateUrl('app_allowance', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            return $this->redirect($this->topUps->createCheckoutSession($user, $successUrl, $cancelUrl));
        } catch (\Throwable) {
            $this->addFlash('error', $this->translator->trans('allowance_page.flash.checkout_failed'));

            return $this->redirectToRoute('app_allowance');
        }
    }

    /**
     * Back from Stripe: credit through the fast path (the webhook is authoritative and
     * reconciles regardless), then Post/Redirect/Get so a refresh can't re-run it.
     */
    #[Route(
        '/allowance/top-up/success',
        name: 'app_allowance_top_up_success',
        methods: ['GET'],
        condition: "service('hosting').isHosted()",
    )]
    public function success(Request $request): Response
    {
        $sessionId = (string) $request->query->get('session_id', '');
        // Applied covers the usual order too: the webhook credited it before the
        // browser got back (Copilot catch on #248).
        $applied = $sessionId !== ''
            && $this->topUps->creditFromSessionId($this->user(), $sessionId)->isApplied();

        $this->addFlash('success', $this->translator->trans(
            $applied ? 'allowance_page.flash.topped_up' : 'allowance_page.flash.pending',
            ['count' => AnalysisAllowance::TOP_UP_ANALYSES],
        ));

        return $this->redirectToRoute('app_allowance');
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}

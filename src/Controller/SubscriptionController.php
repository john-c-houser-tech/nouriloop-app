<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\SupportedLocale;
use App\Entity\User;
use App\Service\AccountDeletionService;
use App\Service\AnalysisAllowance;
use App\Service\SubscriptionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Stripe Checkout / Billing Portal entry points plus the public pricing page and the
 * per-user subscription settings page. All Stripe SDK work lives in
 * {@see SubscriptionService}; this controller only validates input, builds URLs, and
 * redirects to Stripe's hosted pages.
 */
class SubscriptionController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly AccountDeletionService $accountDeletion,
        private readonly AnalysisAllowance $allowance,
        private readonly string $stripePublishableKey,
        private readonly string $loopMonthlyPriceId,
        private readonly string $loopPlusMonthlyPriceId,
    ) {
    }

    #[Route('/pricing', name: 'app_pricing', methods: ['GET'], condition: "service('hosting').isHosted()")]
    #[Route(
        '/{_locale}/pricing',
        name: 'app_pricing_i18n',
        requirements: ['_locale' => SupportedLocale::URL_PREFIX_REQUIREMENT],
        methods: ['GET'],
        condition: "service('hosting').isHosted()",
    )]
    public function pricing(TranslatorInterface $translator): Response
    {
        $user = $this->getUser();

        return $this->render('subscription/pricing.html.twig', [
            'publishableKey'        => $this->stripePublishableKey,
            'loopMonthlyPriceId'    => $this->loopMonthlyPriceId,
            'loopPlusMonthlyPriceId' => $this->loopPlusMonthlyPriceId,
            // A subscriber's cards follow the plan Stripe bills (a comp can lift the
            // effective tier above it); their plan buttons say "Switch" and lead to the
            // portal (checkout()).
            'currentTier'           => $user instanceof User
                ? ($this->subscriptions->billedTier($user) ?? $this->subscriptions->effectiveTier($user))
                : null,
            'switchInPortal'        => $user instanceof User && $this->subscriptions->hasLiveSubscription($user),
            // Translated at render time — the request locale is already set.
            'seo_description'       => $translator->trans('pricing_page.seo_description'),
        ]);
    }

    #[Route(
        '/subscription/checkout',
        name: 'app_subscription_checkout',
        methods: ['POST'],
        condition: "service('hosting').isHosted()",
    )]
    #[IsGranted('ROLE_USER')]
    public function checkout(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('subscription-checkout', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired — please try again.');

            return $this->redirectToRoute('app_pricing');
        }

        $priceId = (string) $request->request->get('price_id', '');
        // Never pass an arbitrary price id to Stripe — only our two known plan prices.
        if (!$this->subscriptions->isKnownPriceId($priceId)) {
            return new Response('Unknown plan.', Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();
        \assert($user instanceof User);

        // A subscriber changes plan in the portal. A second Checkout would add a second
        // subscription to the same customer and bill both (PR 8 test-mode run, 2026-10-13).
        if ($this->subscriptions->hasLiveSubscription($user)) {
            return $this->redirectToPortal($user, switchPlan: true);
        }

        // Resubscribing is an unambiguous "I changed my mind" — don't also make them
        // separately click "Cancel deletion" first.
        if ($user->getDeletionRequestedAt() !== null) {
            $this->accountDeletion->cancelDeletion($user);
        }

        $successUrl = $this->generateUrl('app_subscription_success', [], UrlGeneratorInterface::ABSOLUTE_URL)
            . '?session_id={CHECKOUT_SESSION_ID}';
        // Route the cancel return through the dedicated handler (which bounces to
        // pricing) so it — and its PUBLIC_ACCESS rule — serves its documented purpose.
        $cancelUrl = $this->generateUrl('app_subscription_cancel_checkout', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $url = $this->subscriptions->createCheckoutSession($user, $priceId, $successUrl, $cancelUrl);
        } catch (\Throwable $e) {
            $this->addFlash('error', "We couldn't start checkout. Please try again in a moment.");

            return $this->redirectToRoute('app_pricing');
        }

        return $this->redirect($url);
    }

    #[Route(
        '/subscription/success',
        name: 'app_subscription_success',
        methods: ['GET'],
        condition: "service('hosting').isHosted()",
    )]
    #[IsGranted('ROLE_USER')]
    public function success(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        // Arrival straight from Stripe Checkout carries ?session_id=. Fast-path sync so
        // the page reflects the new plan immediately (the webhook is authoritative and
        // reconciles regardless), record the GA4 purchase conversion as a one-shot flash,
        // then Post/Redirect/Get to a clean URL — so a refresh or bookmark of the
        // session_id URL can't re-run the sync or re-fire the conversion.
        $sessionId = (string) $request->query->get('session_id', '');
        if ($sessionId !== '') {
            $this->subscriptions->syncFromCheckoutSession($user, $sessionId);
            $this->addFlash('track_event', 'purchase');

            return $this->redirectToRoute('app_subscription_success');
        }

        return $this->render('subscription/success.html.twig', [
            'tier' => $this->subscriptions->effectiveTier($user),
        ]);
    }

    #[Route(
        '/subscription/cancel-checkout',
        name: 'app_subscription_cancel_checkout',
        methods: ['GET'],
        condition: "service('hosting').isHosted()",
    )]
    public function cancelCheckout(): Response
    {
        return $this->redirectToRoute('app_pricing');
    }

    #[Route(
        '/subscription/portal',
        name: 'app_subscription_portal',
        methods: ['POST'],
        condition: "service('hosting').isHosted()",
    )]
    #[IsGranted('ROLE_USER')]
    public function portal(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('subscription-portal', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired — please try again.');

            return $this->redirectToRoute('app_settings_subscription');
        }

        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->redirectToPortal($user);
    }

    private function redirectToPortal(User $user, bool $switchPlan = false): Response
    {
        $returnUrl = $this->generateUrl('app_settings_subscription', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $url = $this->subscriptions->createPortalSession($user, $returnUrl, $switchPlan);
        } catch (\Throwable $e) {
            $this->addFlash('error', "We couldn't open the billing portal. Please try again in a moment.");

            return $this->redirectToRoute('app_settings_subscription');
        }

        return $this->redirect($url);
    }

    /**
     * Per-user subscription settings: current plan/status, a link to upgrade, and the
     * "Manage subscription" portal button for existing subscribers.
     */
    #[Route(
        '/settings/subscription',
        name: 'app_settings_subscription',
        methods: ['GET'],
        condition: "service('hosting').isHosted()",
    )]
    #[IsGranted('ROLE_USER')]
    public function settings(): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->render('settings/subscription.html.twig', [
            'tier'           => $this->subscriptions->effectiveTier($user),
            'hasActiveStripe' => $user->getStripeCustomerId() !== null,
            'allowance'      => $this->allowance->canTopUp($user) ? $this->allowance->status($user) : null,
            // A canceled plan's last day, so the user can see the cancellation took. The
            // plan named is the one Stripe is ending, not a comp above it (Copilot on #250).
            'endsAt'         => $this->subscriptions->scheduledEnd($user),
            'endingPlan'     => $user->getSubscriptionTier(),
        ]);
    }
}

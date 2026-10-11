<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\OnboardingService;
use App\Service\TimezoneChanger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Backs the first-login onboarding overlay (rendered in base.html.twig). The overlay
 * itself rides along on every authenticated page, so the only routes here are the
 * actions it POSTs: permanent dismiss, the notifications mini-form (step 3), and the
 * optional health "Maybe later" / seen marker (step 4). Meal (step 1) and goal (step 2)
 * are marked by their own controllers when the user actually completes them.
 */
class OnboardingController extends AbstractController
{
    /** Stable deep-link target (e.g. from emails). The overlay shows via base.html.twig. */
    #[Route('/onboarding', name: 'app_onboarding', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/onboarding/dismiss', name: 'app_onboarding_dismiss', methods: ['POST'])]
    public function dismiss(Request $request, OnboardingService $onboarding): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        if ($this->isCsrfTokenValid('onboarding-dismiss', (string) $request->request->get('_token'))) {
            $onboarding->dismiss($user);
        }

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * Step 3 — confirm timezone + the four (email) notification toggles. Email-only here;
     * SMS opt-in keeps its TCPA consent flow on the full Settings ▸ Notifications page.
     * The step is marked "seen" on submit even if every toggle is left off.
     */
    #[Route('/onboarding/notifications', name: 'app_onboarding_notifications', methods: ['POST'])]
    public function notifications(
        Request $request,
        OnboardingService $onboarding,
        TranslatorInterface $translator,
        TimezoneChanger $timezones,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$this->isCsrfTokenValid('onboarding-notifications', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_dashboard');
        }

        $tz = (string) $request->request->get('timezone', '');
        if ($tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            // No-op when unchanged (the usual case — the step echoes the stored
            // zone); a real change also reschedules active goals' nextRunAt.
            $timezones->change($user, $tz);
        }

        if ($request->request->get('preset') === 'recommended') {
            // One-tap "Recommended" (E17): just the evening no-meals nudge — the only
            // notice meaningful before any data exists. Overrides the checkboxes;
            // everything stays editable on Settings ▸ Notifications.
            $user->setNotifyNoMeals(true);
            $user->setNotifyStaleMetrics(false);
            $user->setNotifyCalorieRange(false);
            $user->setNotifySupplements(false);
        } else {
            $user->setNotifyNoMeals($request->request->getBoolean('notify_no_meals'));
            $user->setNotifyStaleMetrics($request->request->getBoolean('notify_stale_metrics'));
            $user->setNotifyCalorieRange($request->request->getBoolean('notify_calorie_range'));
            $user->setNotifySupplements($request->request->getBoolean('notify_supplements'));
        }

        // markStep flushes (and folds in the toggle/timezone changes on the same User).
        $onboarding->markStep($user, 'notifications');
        $this->addFlash('success', $translator->trans('onboarding.notifications.saved'));

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * Settings ▸ Notifications toggle: show or permanently hide the setup checklist.
     * Re-enabling only has an effect before onboarding completes (needsOnboarding()
     * is already false once all required steps are done).
     */
    #[Route('/onboarding/visibility', name: 'app_onboarding_visibility', methods: ['POST'])]
    public function visibility(
        Request $request,
        OnboardingService $onboarding,
        TranslatorInterface $translator,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        if ($this->isCsrfTokenValid('onboarding-visibility', (string) $request->request->get('_token'))) {
            if ($request->request->getBoolean('show_checklist')) {
                $onboarding->enable($user);
            } else {
                $onboarding->dismiss($user);
            }
            $this->addFlash('success', $translator->trans('onboarding.visibility_saved'));
        }

        return $this->redirectToRoute('app_settings_notifications');
    }

    /**
     * Step 4 (optional) — mark the health step seen, whether the user clicked
     * "Maybe later" or is on their way to the importer. Frictionless; never required.
     */
    #[Route('/onboarding/step/health', name: 'app_onboarding_step_health', methods: ['POST'])]
    public function stepHealth(Request $request, OnboardingService $onboarding): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        if ($this->isCsrfTokenValid('onboarding-step', (string) $request->request->get('_token'))) {
            $onboarding->markStep($user, 'health');
        }

        // "Connect your CGM" wants the guided setup; "Import a file" wants the
        // importer; "Skip this step" wants home.
        return match ($request->request->get('then')) {
            'connect' => $this->redirectToRoute('app_connect_cgm'),
            'import'  => $this->redirectToRoute('app_health_metrics_import'),
            default   => $this->redirectToRoute('app_dashboard'),
        };
    }
}

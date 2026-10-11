<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PrivacyController extends AbstractController
{
    #[Route('/privacy/sms', name: 'app_privacy_sms', methods: ['GET'])]
    public function sms(): Response
    {
        return $this->render('privacy/sms.html.twig', [
            'seo_description' => 'How NouriLoop\'s SMS text-message program handles your data: '
                . 'consent, message types, frequency, and opt-out.',
        ]);
    }

    /**
     * Public, non-functional preview of the logged-in SMS opt-in screen, so
     * A2P 10DLC / carrier reviewers can verify the call-to-action and consent
     * language without an account. The real form lives behind login at
     * Settings ▸ Notifications; this mirrors it exactly but submits nothing.
     */
    #[Route('/privacy/sms-optin', name: 'app_privacy_sms_optin', methods: ['GET'])]
    public function smsOptin(): Response
    {
        return $this->render('privacy/sms_optin.html.twig');
    }

    #[Route('/privacy/health', name: 'app_privacy_health', methods: ['GET'])]
    public function health(): Response
    {
        return $this->render('privacy/health.html.twig', [
            'seo_description' => 'How NouriLoop collects, uses, and protects your consumer health data.',
        ]);
    }

    #[Route('/privacy/terms', name: 'app_privacy_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('privacy/terms.html.twig', [
            'seo_description' => 'NouriLoop Terms of Service: subscriptions, billing, acceptable use, and disclaimers.',
        ]);
    }
}

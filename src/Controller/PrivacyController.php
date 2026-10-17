<?php

declare(strict_types=1);

namespace App\Controller;

use App\Hosting\Brand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PrivacyController extends AbstractController
{
    #[Route('/privacy/sms', name: 'app_privacy_sms', methods: ['GET'])]
    public function sms(Brand $brand): Response
    {
        if (!$brand->isNouriLoop()) {
            return $this->operatorDocument($brand, 'privacy');
        }
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
    public function smsOptin(Brand $brand): Response
    {
        if (!$brand->isNouriLoop()) {
            return $this->operatorDocument($brand, 'privacy');
        }
        return $this->render('privacy/sms_optin.html.twig');
    }

    #[Route('/privacy/health', name: 'app_privacy_health', methods: ['GET'])]
    public function health(Brand $brand): Response
    {
        if (!$brand->isNouriLoop()) {
            return $this->operatorDocument($brand, 'privacy');
        }
        return $this->render('privacy/health.html.twig', [
            'seo_description' => 'How NouriLoop collects, uses, and protects your consumer health data.',
        ]);
    }

    #[Route('/privacy/terms', name: 'app_privacy_terms', methods: ['GET'])]
    public function terms(Brand $brand): Response
    {
        if (!$brand->isNouriLoop()) {
            return $this->operatorDocument($brand, 'terms');
        }
        return $this->render('privacy/terms.html.twig', [
            'seo_description' => 'NouriLoop Terms of Service: subscriptions, billing, acceptable use, and disclaimers.',
        ]);
    }

    /**
     * A self-hosted copy: NouriLoop LLC's privacy notice and terms don't apply to someone
     * else's server (configurable branding, Plan Q11c). Send visitors to the operator's own
     * document when they gave one (PRIVACY_URL / TERMS_URL), else show a short page that
     * says who runs this copy.
     */
    private function operatorDocument(Brand $brand, string $kind): Response
    {
        $url = $kind === 'terms' ? $brand->termsUrl() : $brand->privacyUrl();
        if ($url !== '') {
            return $this->redirect($url);
        }

        return $this->render('privacy/operator.html.twig', ['kind' => $kind]);
    }
}

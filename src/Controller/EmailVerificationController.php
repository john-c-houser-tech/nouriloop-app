<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * "Resend confirmation email" — the CTA on the unverified-email banner
 * (templates/_email_verification_banner.html.twig). Sends a fresh 24 h login
 * link; consuming it stamps verification via LoginLinkVerificationListener.
 * Behind the catch-all ROLE_USER rule, so no enumeration concerns here — the
 * user is resending to their own address.
 */
final class EmailVerificationController extends AbstractController
{
    /** Same 24 h lifetime as the welcome email's confirm link. */
    private const CONFIRM_LINK_LIFETIME = 86400;

    #[Route('/verify-email/resend', name: 'app_verify_email_resend', methods: ['POST'])]
    public function resend(
        Request $request,
        LoginLinkHandlerInterface $loginLinkHandler,
        MailerInterface $mailer,
        LoggerInterface $logger,
        #[Target('email_verification')] RateLimiterFactoryInterface $limiter,
        LocaleSwitcher $localeSwitcher,
        TranslatorInterface $translator,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $redirect = $this->redirectToRoute('app_dashboard');

        if (!$this->isCsrfTokenValid('verify-email-resend', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Something went wrong. Please try again.');

            return $redirect;
        }

        if ($user->isEmailVerified()) {
            return $redirect; // Nothing to do — e.g. a double-click after verifying.
        }

        if (!$limiter->create('verify-email-user-' . $user->getId())->consume()->isAccepted()) {
            $this->addFlash(
                'error',
                'A confirmation email was sent recently — please check your inbox (and spam folder) first.',
            );

            return $redirect;
        }

        try {
            $confirmUrl = $loginLinkHandler
                ->createLoginLink($user, lifetime: self::CONFIRM_LINK_LIFETIME)
                ->getUrl();

            // The banner CTA runs in the user's own session, but render in
            // their SAVED locale for consistency with the other senders.
            $localeSwitcher->runWithLocale($user->getLocale()->value, function () use (
                $user,
                $confirmUrl,
                $mailer,
                $translator,
            ): void {
                $email = (new TemplatedEmail())
                    ->to((string) $user->getEmail())
                    ->subject($translator->trans('delivery.transactional.verify_subject'))
                    ->htmlTemplate('email/verify_email.html.twig')
                    ->context(['user' => $user, 'confirmUrl' => $confirmUrl]);

                $mailer->send($email);
            });
            $this->addFlash('success', sprintf('Confirmation email sent to %s.', $user->getEmail()));
        } catch (\Throwable $e) {
            $logger->warning('Failed to send verification email to {email}: {error}', [
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            $this->addFlash('error', 'We could not send the email just now. Please try again later.');
        }

        return $redirect;
    }
}

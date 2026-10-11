<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\LoginLinkRequestFormType;
use App\Repository\UserRepository;
use App\Service\TurnstileValidator;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Passwordless "email me a sign-in link" (magic links) — the convenience path for
 * users who struggle with passwords. Security posture: requesting a link never
 * reveals whether an account exists (uniform "sent" outcome, like the password
 * reset flow), and the emailed link is single-use with a 15-minute lifetime
 * (firewall `login_link` config). Note that email access already resets the
 * password, so this adds convenience without lowering the auth bar.
 */
final class LoginLinkController extends AbstractController
{
    /** Mirrors the firewall's `login_link.lifetime` (900 s) for the email copy. */
    private const LINK_LIFETIME_MINUTES = 15;

    #[Route('/login/link', name: 'app_login_link_request', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        UserRepository $userRepository,
        LoginLinkHandlerInterface $loginLinkHandler,
        MailerInterface $mailer,
        LoggerInterface $logger,
        TurnstileValidator $turnstile,
        TranslatorInterface $translator,
        LocaleSwitcher $localeSwitcher,
        #[Target('login_link')] RateLimiterFactoryInterface $loginLinkLimiter,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $form = $this->createForm(LoginLinkRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Honeypot tripped → look successful but send nothing.
            if (trim((string) $form->get('website')->getData()) !== '') {
                return $this->redirectToRoute('app_login_link_sent');
            }

            if (!$turnstile->verify($request->request->get('cf-turnstile-response'), $request->getClientIp())) {
                $this->addFlash('error', $translator->trans('auth.human_check_failed'));

                return $this->render('security/login_link_request.html.twig', [
                    'form' => $form,
                    'turnstileSiteKey' => $turnstile->isEnabled() ? $turnstile->siteKey() : '',
                ]);
            }

            $limiter = $loginLinkLimiter->create('login-link-ip-' . $request->getClientIp());
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('error', $translator->trans('auth.link.too_many'));

                return $this->render('security/login_link_request.html.twig', [
                    'form' => $form,
                    'turnstileSiteKey' => $turnstile->isEnabled() ? $turnstile->siteKey() : '',
                ], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }

            $user = $userRepository->findOneBy(['email' => (string) $form->get('email')->getData()]);
            if ($user instanceof User && $user->isActive()) {
                $this->sendLoginLink($user, $loginLinkHandler, $mailer, $logger, $localeSwitcher, $translator);
            }

            // Always the same outcome — never reveal whether the account exists.
            return $this->redirectToRoute('app_login_link_sent');
        }

        return $this->render('security/login_link_request.html.twig', [
            'form' => $form,
            'turnstileSiteKey' => $turnstile->isEnabled() ? $turnstile->siteKey() : '',
        ]);
    }

    #[Route('/login/link/sent', name: 'app_login_link_sent', methods: ['GET'])]
    public function sent(): Response
    {
        return $this->render('security/login_link_sent.html.twig');
    }

    /**
     * "Continue on my phone" — a signed-in user mails a sign-in link to their OWN
     * address, so they can pick the session up on a device with a usable camera.
     * Offered from the onboarding meal step on pointer devices (see
     * `templates/onboarding/_step_meal.html.twig`).
     *
     * ⚠️ `^/login` is PUBLIC_ACCESS in security.yaml (the anonymous request form
     * lives under it), so this action must assert ROLE_USER itself — the firewall
     * will not. The recipient is always `$this->getUser()`'s address and is never
     * read from the request, which is what makes this safe to expose: there is no
     * arbitrary address to mail and nothing to enumerate.
     *
     * Unlike the anonymous path, a transport failure is reported honestly (no
     * enumeration concern once you're signed in) — a silent "sent" would leave the
     * user waiting on an email that never comes.
     */
    #[Route('/login/link/to-phone', name: 'app_login_link_to_phone', methods: ['POST'])]
    public function toPhone(
        Request $request,
        LoginLinkHandlerInterface $loginLinkHandler,
        MailerInterface $mailer,
        LoggerInterface $logger,
        TranslatorInterface $translator,
        LocaleSwitcher $localeSwitcher,
        #[Target('login_link')] RateLimiterFactoryInterface $loginLinkLimiter,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('login-link-phone', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $translator->trans('auth.link.to_phone_failed'));

            return $this->redirectToRoute('app_dashboard');
        }

        // Keyed by user id — a distinct namespace from the anonymous path's
        // per-IP keys, so one household's devices don't share an allowance.
        $limiter = $loginLinkLimiter->create('login-link-user-' . $user->getId());
        if (!$limiter->consume()->isAccepted()) {
            $this->addFlash('error', $translator->trans('auth.link.too_many'));

            return $this->redirectToRoute('app_dashboard');
        }

        $sent = $this->sendLoginLink($user, $loginLinkHandler, $mailer, $logger, $localeSwitcher, $translator);

        $this->addFlash(
            $sent ? 'success' : 'error',
            $translator->trans($sent ? 'auth.link.to_phone_sent' : 'auth.link.to_phone_failed'),
        );

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * The URL the email points at. GET renders a confirm page whose button POSTs
     * back to this same URL (query string included); the firewall's login-link
     * authenticator (`check_post_only`) intercepts the POST, so the method body
     * never runs for it. The GET indirection exists because email clients and
     * corporate scanners prefetch links — a scanner GET must not consume the
     * single-use hash, and scanners don't submit forms.
     */
    #[Route('/login/check', name: 'app_login_check_link', methods: ['GET', 'POST'])]
    public function check(Request $request): Response
    {
        // A bare visit without link params has nothing to confirm.
        if (!$request->query->has('hash')) {
            return $this->redirectToRoute($this->getUser() ? 'app_dashboard' : 'app_login');
        }

        // Deliberately NO already-logged-in redirect when link params are
        // present: the common journey is register → password login → open the
        // welcome email's confirm link, and skipping the page here would
        // silently drop the link consumption that stamps email verification
        // (hit in prod on launch day). The POST simply re-authenticates the
        // link's user — harmless for the same account, ordinary login if not.
        return $this->render('security/login_link_confirm.html.twig');
    }

    /**
     * Best-effort for the anonymous path: a transport failure only happens after a
     * successful account lookup, so surfacing it would reveal the account exists.
     * Log and keep the uniform "sent" outcome (same trade-off as the welcome-email
     * pattern) — that caller ignores the return value on purpose. The signed-in
     * "continue on my phone" caller has no enumeration concern and does use it.
     *
     * @return bool whether the message reached the transport
     */
    private function sendLoginLink(
        User $user,
        LoginLinkHandlerInterface $loginLinkHandler,
        MailerInterface $mailer,
        LoggerInterface $logger,
        LocaleSwitcher $localeSwitcher,
        TranslatorInterface $translator,
    ): bool {
        try {
            $details = $loginLinkHandler->createLoginLink($user);

            // Rendered in the ACCOUNT's locale (not the requester's browser —
            // they may not be the same person/device).
            $localeSwitcher->runWithLocale($user->getLocale()->value, function () use (
                $user,
                $details,
                $mailer,
                $translator,
            ): void {
                $email = (new TemplatedEmail())
                    ->to((string) $user->getEmail())
                    ->subject($translator->trans('delivery.transactional.login_link_subject'))
                    ->htmlTemplate('email/login_link.html.twig')
                    ->context([
                        'user' => $user,
                        'loginUrl' => $details->getUrl(),
                        'expiresMinutes' => self::LINK_LIFETIME_MINUTES,
                    ]);

                $mailer->send($email);
            });

            return true;
        } catch (\Throwable $e) {
            $logger->warning('Failed to send sign-in link to {email}: {error}', [
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return false;
        }
    }
}

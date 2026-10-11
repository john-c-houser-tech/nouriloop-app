<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\SupportedLocale;
use App\Enum\UnitSystem;
use App\Form\RegistrationFormType;
use App\Repository\InvitationCodeRepository;
use App\Service\AttributionCapture;
use App\Service\EmailDeliverabilityChecker;
use App\Service\InvitationCodeManager;
use App\Service\SubscriptionService;
use App\Service\TurnstileValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class RegistrationController extends AbstractController
{
    /**
     * Lifetime of the welcome email's "Confirm your email & sign in" login link.
     * 24 h — people don't open welcome emails within the sign-in flow's 15 min.
     * Note the single-use cache only remembers consumption for the firewall's
     * 900 s, so within its 24 h this link is effectively re-usable by the inbox
     * owner — same power as a password-reset email, which is fine.
     */
    private const WELCOME_LINK_LIFETIME = 86400;

    #[Route('/register', name: 'app_register')]
    #[Route(
        '/{_locale}/register',
        name: 'app_register_i18n',
        requirements: ['_locale' => SupportedLocale::URL_PREFIX_REQUIREMENT],
    )]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        LoggerInterface $logger,
        InvitationCodeRepository $invitationCodes,
        InvitationCodeManager $invitationCodeManager,
        SubscriptionService $subscriptions,
        TurnstileValidator $turnstile,
        LoginLinkHandlerInterface $loginLinkHandler,
        TranslatorInterface $translator,
        Security $security,
        EmailDeliverabilityChecker $emailChecker,
        AttributionCapture $attribution,
        string $adminEmail,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Honeypot tripped → look successful but create nothing.
            if (trim((string) $form->get('website')->getData()) !== '') {
                $this->addFlash('success', $translator->trans('auth.register.success'));

                return $this->redirectToRoute('app_login');
            }

            if (!$turnstile->verify($request->request->get('cf-turnstile-response'), $request->getClientIp())) {
                $this->addFlash('error', $translator->trans('auth.human_check_failed'));

                return $this->render('registration/register.html.twig', [
                    'form' => $form,
                    'turnstileSiteKey' => $turnstile->isEnabled() ? $turnstile->siteKey() : '',
                ]);
            }

            // Deliverability warning — warn, never block (John's call 2026-07-24:
            // a typo'd address means no reminders and an unrecoverable password,
            // but the user is the authority on their own address). One warning
            // per address: the re-render carries the warned address in a hidden
            // email_warning_ack field, and an unchanged resubmit is accepted.
            // Editing the address re-arms the check.
            $emailWarning = $emailChecker->assess((string) $user->getEmail());
            if ($emailWarning !== null && $request->request->get('email_warning_ack') !== $user->getEmail()) {
                return $this->render('registration/register.html.twig', [
                    'form' => $form,
                    'turnstileSiteKey' => $turnstile->isEnabled() ? $turnstile->siteKey() : '',
                    'emailWarning' => $emailWarning,
                    'emailWarningAck' => $user->getEmail(),
                ]);
            }

            // Code is optional (public soft-launch). Blank → Free tier. A non-blank
            // code must be valid: redeem it, and grant the Loop+ comp if it's a beta code.
            $codeInput = trim((string) $form->get('invitationCode')->getData());
            $code = $codeInput === '' ? null : $invitationCodes->findValid($codeInput, new \DateTimeImmutable());

            if ($codeInput !== '' && $code === null) {
                // Generic message — don't reveal whether it's unknown/used/expired.
                $form->get('invitationCode')->addError(
                    new FormError($translator->trans('auth.register.invalid_coupon')),
                );

                return $this->render('registration/register.html.twig', [
                    'form' => $form,
                    'turnstileSiteKey' => $turnstile->isEnabled() ? $turnstile->siteKey() : '',
                ]);
            }

            $user->setPassword(
                $passwordHasher->hashPassword($user, $form->get('plainPassword')->getData())
            );

            // Browser-detected timezone (a suggestion); keep the default if it's
            // blank or not a recognised IANA id. The user can change it in Settings.
            $tz = trim((string) $form->get('timezone')->getData());
            if ($tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                $user->setTimezone($tz);
            }

            // Default display units from the timezone (US → imperial, else metric);
            // changeable in Settings ▸ Units.
            $user->setUnitSystem(UnitSystem::defaultForTimezone($user->getTimezone()));

            // Default UI/AI language from the browser's Accept-Language (best
            // supported match, English otherwise); Settings ▸ Language to change.
            // A locale-prefixed register page (/es/register — i18n Phase 4) is an
            // explicit language choice: the language they converted in is the
            // language the app greets them in, overriding the Accept-Language guess.
            $urlPrefix = (string) $request->attributes->get('_locale', '');
            $user->setLocale(
                ($urlPrefix !== '' ? SupportedLocale::fromUrlPrefix($urlPrefix) : null)
                    ?? SupportedLocale::fromRequest($request),
            );

            // First-touch campaign attribution (utm_* / gclid) captured by
            // AttributionSubscriber on whichever ad-fed page started this session.
            // Stored in aggregate only — see AttributionCapture.
            $attribution->applyTo($user, $request);

            $entityManager->persist($user);

            // With a (valid) code: redeem it, and a beta code grants the complimentary
            // time-limited Loop+ comp (see SubscriptionService::grantBetaTesterComp).
            // No code → Free tier.
            if ($code !== null) {
                $invitationCodeManager->redeem($code, $user);
                if ($code->isBetaTester()) {
                    $subscriptions->grantBetaTesterComp($user);
                }
            }

            $entityManager->flush();

            $this->sendWelcomeEmail($user, $mailer, $logger, $loginLinkHandler);
            $this->notifyAdmins($user, $adminEmail, $mailer, $logger);

            $this->addFlash('success', $translator->trans('auth.register.welcome'));
            // One-shot GA4/Meta conversion signal, emitted on the next page render (the
            // dashboard) by base.html.twig's analytics block. The pending flash is what
            // makes the Meta Pixel load once on that authenticated page — the same
            // mechanism as the purchase event on /subscription/success. No PII.
            $this->addFlash('track_event', 'sign_up');

            // The user just proved they own these credentials — sign them in
            // programmatically (with the app's always-on remember-me cookie, like any
            // form login) and land them on the dashboard, where the onboarding overlay
            // is waiting. The old bounce through the login page cost two screens and a
            // re-typed password for nothing.
            $security->login($user, 'form_login', 'main', [(new RememberMeBadge())->enable()]);

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('registration/register.html.twig', [
            'form' => $form,
            'turnstileSiteKey' => $turnstile->isEnabled() ? $turnstile->siteKey() : '',
        ]);
    }

    /**
     * Sends the new user a welcome email. Best-effort: a mail failure must never
     * break registration (same pattern as the admin new-user notice). Carries a
     * "Confirm your email & sign in" CTA — a 24 h login link whose consumption
     * stamps email verification via LoginLinkVerificationListener.
     */
    private function sendWelcomeEmail(
        User $user,
        MailerInterface $mailer,
        LoggerInterface $logger,
        LoginLinkHandlerInterface $loginLinkHandler,
    ): void {
        try {
            $confirmUrl = $loginLinkHandler
                ->createLoginLink($user, lifetime: self::WELCOME_LINK_LIFETIME)
                ->getUrl();

            $email = (new TemplatedEmail())
                ->to((string) $user->getEmail())
                ->subject('Welcome to NouriLoop')
                ->htmlTemplate('email/welcome.html.twig')
                ->context(['user' => $user, 'confirmUrl' => $confirmUrl]);

            $mailer->send($email);
        } catch (\Throwable $e) {
            $logger->warning('Failed to send welcome email to {email}: {error}', [
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Emails the team's admin address about a new registration. Best-effort: a mail
     * failure must never break registration. Recipient is the configured ADMIN_EMAIL
     * (decoupled from any admin's login email), not the admin account itself.
     */
    private function notifyAdmins(
        User $user,
        string $adminEmail,
        MailerInterface $mailer,
        LoggerInterface $logger,
    ): void {
        if ($adminEmail === '') {
            return;
        }

        try {
            $email = (new TemplatedEmail())
                ->to($adminEmail)
                ->subject('New NouriLoop user registered')
                ->htmlTemplate('email/new_user.html.twig')
                ->context(['newUser' => $user]);

            $mailer->send($email);
        } catch (\Throwable $e) {
            $logger->warning('Failed to send new-user admin notice for {email}: {error}', [
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}

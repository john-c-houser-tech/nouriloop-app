<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\Glp1ModeFormType;
use App\Form\LanguageFormType;
use App\Form\NotificationPreferencesFormType;
use App\Form\ProfileFormType;
use App\Form\TimezoneFormType;
use App\Form\UnitsFormType;
use App\Hosting\HostingMode;
use App\Outbound\Reachability;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Service\AccountDeletionService;
use App\Service\ApiTokenManager;
use App\Service\EmailDeliverabilityChecker;
use App\Service\LocaleFormatter;
use App\Service\SubscriptionService;
use App\Service\SymptomTypeManager;
use App\Service\TimezoneChanger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

class SettingsController extends AbstractController
{
    /**
     * Single source of truth for the /settings hub's grouping — mirrors
     * HelpController::TOPICS. Each item's `route` must be a real, argument-less route.
     *
     * @var array<string, array{label: string, items: list<array{route: string, label: string, icon: string}>}>
     */
    public const CATEGORIES = [
        'preferences' => ['label' => 'settings.category.preferences', 'items' => [
            ['route' => 'app_settings_timezone', 'label' => 'settings.item.timezone', 'icon' => 'bi-clock'],
            ['route' => 'app_settings_units', 'label' => 'settings.item.units', 'icon' => 'bi-rulers'],
            ['route' => 'app_settings_language', 'label' => 'settings.item.language', 'icon' => 'bi-translate'],
            ['route' => 'app_settings_glp1', 'label' => 'settings.item.glp1', 'icon' => 'bi-capsule'],
            ['route' => 'app_settings_tone', 'label' => 'settings.item.tone', 'icon' => 'bi-chat-heart'],
        ]],
        'notifications' => ['label' => 'settings.category.notifications', 'items' => [
            ['route' => 'app_settings_notifications', 'label' => 'settings.item.notifications', 'icon' => 'bi-bell'],
        ]],
        'security' => ['label' => 'settings.category.security', 'items' => [
            ['route' => 'app_settings_api_tokens', 'label' => 'settings.item.api_tokens', 'icon' => 'bi-key'],
            ['route' => 'app_settings_ai', 'label' => 'settings.item.ai', 'icon' => 'bi-cpu'],
        ]],
        'billing' => ['label' => 'settings.category.billing', 'items' => [
            ['route' => 'app_settings_subscription', 'label' => 'settings.item.subscription', 'icon' => 'bi-stars'],
        ]],
        'account' => ['label' => 'settings.category.account', 'items' => [
            [
                'route' => 'app_settings_profile',
                'label' => 'settings.item.profile',
                'icon' => 'bi-person',
            ],
            [
                'route' => 'app_settings_account',
                'label' => 'settings.item.account',
                'icon' => 'bi-exclamation-triangle',
            ],
        ]],
    ];

    /**
     * Lifetime of the change-email confirmation link (same 24 h as the verify
     * link). A DateInterval spec on purpose: UriSigner::sign() reads an int as
     * an ABSOLUTE timestamp, so a bare 86400 would mean "expired in 1970".
     */
    private const EMAIL_CHANGE_LINK_TTL = 'P1D';

    #[Route('/settings', name: 'app_settings_index', methods: ['GET'])]
    public function index(HostingMode $hosting): Response
    {
        $categories = self::CATEGORIES;
        if ($hosting->isSelfHosted()) {
            // No billing on a self-hosted instance: its subscription page doesn't exist.
            unset($categories['billing']);
        }

        return $this->render('settings/index.html.twig', [
            'categories' => $categories,
        ]);
    }

    #[Route('/settings/timezone', name: 'app_settings_timezone', methods: ['GET', 'POST'])]
    public function timezone(
        Request $request,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
        TimezoneChanger $timezones,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        // The form mutates the User directly on submit, so remember the old zone
        // to know whether the goals need rescheduling (see TimezoneChanger).
        $previousTimezone = $user->getTimezone();

        $form = $this->createForm(TimezoneFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($user->getTimezone() !== $previousTimezone) {
                $timezones->rescheduleActiveGoals($user);
            }
            $em->flush();
            $this->addFlash('success', $translator->trans('settings.timezone.saved'));

            return $this->redirectToRoute('app_settings_timezone');
        }

        return $this->render('settings/timezone.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * One-click accept for the timezone-mismatch banner (_timezone_banner.html.twig):
     * the browser-detected IANA zone is posted and validated against the whitelist.
     * Invalid/unknown values are silently ignored (the banner's JS is the only caller,
     * so a bad value means tampering, not a user mistake).
     */
    #[Route('/settings/timezone/quick', name: 'app_settings_timezone_quick', methods: ['POST'])]
    public function timezoneQuick(
        Request $request,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
        TimezoneChanger $timezones,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        if ($this->isCsrfTokenValid('timezone-quick', (string) $request->request->get('_token'))) {
            $tz = trim((string) $request->request->get('timezone'));
            if (in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                // Also reschedules active goals' nextRunAt into the new zone.
                $timezones->change($user, $tz);
                $em->flush();
                $this->addFlash('success', $translator->trans('settings.timezone.saved'));
            }
        }

        // Return to the page the banner was on (query string included — dropping
        // it would reset stateful views like /trends?days=90); only ever follow
        // an internal path.
        $referer = (string) $request->headers->get('referer', '');
        $parts = parse_url($referer);
        if (($parts['host'] ?? null) === $request->getHost() && ($parts['path'] ?? '') !== '') {
            $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

            return $this->redirect($parts['path'] . $query);
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/settings/units', name: 'app_settings_units', methods: ['GET', 'POST'])]
    public function units(Request $request, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $form = $this->createForm(UnitsFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', $translator->trans('settings.units.saved'));

            return $this->redirectToRoute('app_settings_units');
        }

        return $this->render('settings/units.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/settings/language', name: 'app_settings_language', methods: ['GET', 'POST'])]
    public function language(Request $request, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $form = $this->createForm(LanguageFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            // Explicit locale: the flash renders after the redirect (where the new
            // preference is active), but it's translated NOW, on a request whose
            // locale is still the pre-save language — so pin it to the new one.
            $this->addFlash(
                'success',
                $translator->trans('settings.language.saved', locale: $user->getLocale()->value),
            );

            return $this->redirectToRoute('app_settings_language');
        }

        return $this->render('settings/language.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/settings/glp1', name: 'app_settings_glp1', methods: ['GET', 'POST'])]
    public function glp1(
        Request $request,
        EntityManagerInterface $em,
        SymptomTypeManager $symptomTypes,
        TranslatorInterface $translator,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $form = $this->createForm(Glp1ModeFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Enabling the mode turns on the GLP-1 symptom check-ins (nausea +
            // fullness); disabling leaves all symptom data and the user's own
            // toggles untouched.
            if ($user->isGlp1Mode()) {
                $symptomTypes->enableGlp1Symptoms($user);
            }
            $em->flush();
            $this->addFlash('success', $translator->trans('settings.glp1.saved'));

            return $this->redirectToRoute('app_settings_glp1');
        }

        return $this->render('settings/glp1.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/settings/tone', name: 'app_settings_tone', methods: ['GET', 'POST'])]
    public function tone(Request $request, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('settings-tone', (string) $request->request->get('_token'))) {
                $this->addFlash('error', $translator->trans('common.session_expired'));

                return $this->redirectToRoute('app_settings_tone');
            }

            // Setters clamp to 1–5, so out-of-range input can't be stored.
            $user->setToneHumor((int) $request->request->get('tone_humor', 3));
            $user->setToneWarmth((int) $request->request->get('tone_warmth', 3));
            $user->setToneEducation((int) $request->request->get('tone_education', 3));
            $em->flush();
            $this->addFlash('success', $translator->trans('settings.tone.saved'));

            return $this->redirectToRoute('app_settings_tone');
        }

        return $this->render('settings/tone.html.twig');
    }

    #[Route('/settings/notifications', name: 'app_settings_notifications', methods: ['GET', 'POST'])]
    public function notifications(
        Request $request,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
        SubscriptionService $subscriptions,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        // SMS sends are a Loop+ feature (`sms`): for non-entitled
        // users the phone + TCPA-consent controls are omitted from the form (so an
        // unsubmitted phone field can't clobber a stored number) and the template
        // shows an upsell hint. The channel preference itself stays shared and
        // unmutated — gating is per-feature at send time.
        $smsAllowed = $subscriptions->hasFeature($user, 'sms');

        $form = $this->createForm(NotificationPreferencesFormType::class, $user, [
            'include_sms' => $smsAllowed,
        ]);
        // Reflect existing consent so the box stays checked when other settings change.
        if ($smsAllowed) {
            $form->get('smsConsent')->setData($user->getSmsConsentAt() !== null);
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $smsSelected = $user->getNotificationChannel()->includesSms();
            $consent = $smsAllowed && (bool) $form->get('smsConsent')->getData();

            // SMS requires both a number and affirmative consent (TCPA/CTIA): the
            // same rule the senders apply (Reachability), asked about what was
            // just posted. Only enforced when the SMS controls are on the form.
            $missing = $smsAllowed && $smsSelected ? Reachability::smsMissing($user->getPhoneNumber(), $consent) : [];
            if (in_array(Reachability::MISSING_PHONE, $missing, true)) {
                $form->get('phoneNumber')->addError(
                    new FormError($translator->trans('settings.notifications.phone_required')),
                );
            }
            if (in_array(Reachability::MISSING_CONSENT, $missing, true)) {
                $form->get('smsConsent')->addError(
                    new FormError($translator->trans('settings.notifications.consent_required')),
                );
            }

            if ($form->isValid()) {
                if ($smsAllowed) {
                    // Record the first consent; clear it when SMS is turned off.
                    $user->setSmsConsentAt(
                        $smsSelected ? ($user->getSmsConsentAt() ?? new \DateTimeImmutable()) : null,
                    );
                }
                $em->flush();
                $this->addFlash('success', $translator->trans('settings.notifications.saved'));

                return $this->redirectToRoute('app_settings_notifications');
            }
        }

        return $this->render('settings/notifications.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Lists the user's API tokens and mints new ones. The plaintext is shown
     * exactly once (via a one-shot flash) right after creation.
     */
    #[Route('/settings/api-tokens', name: 'app_settings_api_tokens', methods: ['GET', 'POST'])]
    public function apiTokens(
        Request $request,
        ApiTokenManager $manager,
        ApiTokenRepository $tokens,
        TranslatorInterface $translator,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('api-token-create', (string) $request->request->get('_token'))) {
                return $this->redirectToRoute('app_settings_api_tokens');
            }

            [$token, $plaintext] = $manager->create($user, (string) $request->request->get('name', ''));

            // One-shot flash: this is the only time the plaintext is ever shown.
            $this->addFlash('api_token_new', $plaintext);
            $this->addFlash('success', $translator->trans(
                'settings.api_tokens.created_flash',
                ['name' => $token->getName()],
            ));

            return $this->redirectToRoute('app_settings_api_tokens');
        }

        return $this->render('settings/api_tokens.html.twig', [
            'tokens' => $tokens->findByUserOrdered($user),
        ]);
    }

    #[Route(
        '/settings/api-tokens/{id}/delete',
        name: 'app_settings_api_token_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function deleteApiToken(
        int $id,
        Request $request,
        ApiTokenRepository $tokens,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
    ): Response {
        $token = $tokens->find($id);
        if ($token === null || $token->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('api-token-delete-' . $id, (string) $request->request->get('_token'))) {
            $em->remove($token);
            $em->flush();
            $this->addFlash('success', $translator->trans('settings.api_tokens.revoked_flash'));
        }

        return $this->redirectToRoute('app_settings_api_tokens');
    }

    /**
     * Settings ▸ Profile: display name + sign-in email. The email change is
     * CONFIRM-FIRST: nothing on the account changes until the NEW address
     * clicks a signed 24 h confirmation link — so a typo in the new address is
     * harmless (the old one keeps working), and the address arrives verified.
     * Changing the email additionally requires the current password (a hijacked
     * session must not be able to redirect the account's recovery channel);
     * a name-only edit never asks for it.
     */
    #[Route('/settings/profile', name: 'app_settings_profile', methods: ['GET', 'POST'])]
    public function profile(
        Request $request,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
        UserRepository $users,
        UserPasswordHasherInterface $passwordHasher,
        EmailDeliverabilityChecker $emailChecker,
        UriSigner $uriSigner,
        MailerInterface $mailer,
        LocaleSwitcher $localeSwitcher,
        LoggerInterface $logger,
        #[Target('email_verification')] RateLimiterFactoryInterface $limiter,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $form = $this->createForm(ProfileFormType::class, [
            'displayName' => $user->getDisplayName(),
            'email' => $user->getEmail(),
        ]);
        $form->handleRequest($request);

        $emailWarning = null;
        $emailWarningAck = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $newName = trim((string) $form->get('displayName')->getData());
            $newEmail = trim((string) $form->get('email')->getData());
            $nameChanged = $newName !== $user->getDisplayName();
            $emailChanged = strcasecmp($newEmail, (string) $user->getEmail()) !== 0;

            if (!$emailChanged) {
                if ($nameChanged) {
                    $user->setDisplayName($newName);
                    $em->flush();
                }
                $this->addFlash('success', $translator->trans('settings.profile.saved'));

                return $this->redirectToRoute('app_settings_profile');
            }

            // Email is changing — the password gate comes first, before any
            // detail (uniqueness, deliverability) is revealed to a hijacker.
            $password = (string) $form->get('current_password')->getData();
            if ($password === '' || !$passwordHasher->isPasswordValid($user, $password)) {
                $form->get('current_password')->addError(
                    new FormError($translator->trans('settings.profile.wrong_password')),
                );
            } elseif ($users->findOneBy(['email' => $newEmail]) !== null) {
                $form->get('email')->addError(
                    new FormError($translator->trans('settings.profile.email_in_use')),
                );
            } else {
                // Same warn-once deliverability check as registration (typo map
                // + MX lookup), acknowledged by an unchanged resubmit.
                $emailWarning = $emailChecker->assess($newEmail);
                if ($emailWarning !== null && $request->request->get('email_warning_ack') !== $newEmail) {
                    $emailWarningAck = $newEmail;
                } else {
                    $emailWarning = null;
                    // Reuses the verify-resend limiter (3/hour per user) so this
                    // can't be used to pepper an arbitrary inbox with mail.
                    if (!$limiter->create('email-change-user-' . $user->getId())->consume()->isAccepted()) {
                        $this->addFlash('error', $translator->trans('settings.profile.rate_limited'));

                        return $this->redirectToRoute('app_settings_profile');
                    }

                    // A pending email change must not orphan an edited name.
                    if ($nameChanged) {
                        $user->setDisplayName($newName);
                        $em->flush();
                    }

                    // `old` binds the link to the address it was issued against:
                    // once the email changes (or another link is consumed), every
                    // earlier link dies with it — no 24 h replay window in which a
                    // superseded request can flip the account back (Copilot catch
                    // on PR #180).
                    $confirmUrl = $uriSigner->sign(
                        $this->generateUrl(
                            'app_settings_profile_confirm_email',
                            ['uid' => $user->getId(), 'new' => $newEmail, 'old' => (string) $user->getEmail()],
                            UrlGeneratorInterface::ABSOLUTE_URL,
                        ),
                        new \DateInterval(self::EMAIL_CHANGE_LINK_TTL),
                    );

                    // A transport failure must surface as a retryable flash, not
                    // a 500 (the limiter token is already consumed — acceptable,
                    // it's 3/hour and a failed send is rare).
                    try {
                        $localeSwitcher->runWithLocale($user->getLocale()->value, function () use (
                            $user,
                            $newEmail,
                            $confirmUrl,
                            $mailer,
                            $translator,
                        ): void {
                            $mailer->send((new TemplatedEmail())
                                ->to($newEmail)
                                ->subject($translator->trans('delivery.transactional.email_change_subject'))
                                ->htmlTemplate('email/confirm_email_change.html.twig')
                                ->context(['user' => $user, 'confirmUrl' => $confirmUrl, 'newEmail' => $newEmail]));
                        });
                    } catch (\Throwable $e) {
                        $logger->warning('Failed to send email-change confirmation to {email}: {error}', [
                            'email' => $newEmail,
                            'error' => $e->getMessage(),
                            'exception' => $e,
                        ]);
                        $this->addFlash('error', $translator->trans('settings.profile.send_failed'));

                        return $this->redirectToRoute('app_settings_profile');
                    }

                    $this->addFlash(
                        'success',
                        $translator->trans('settings.profile.confirm_sent', ['email' => $newEmail]),
                    );

                    return $this->redirectToRoute('app_settings_profile');
                }
            }
        }

        return $this->render('settings/profile.html.twig', [
            'form' => $form,
            'emailWarning' => $emailWarning,
            'emailWarningAck' => $emailWarningAck,
        ]);
    }

    /**
     * The emailed confirmation link's target. GET is safe here (unlike the
     * magic-link consume): mail scanners prefetch unauthenticated and just
     * bounce off the login wall — only the signed-in account owner can apply
     * the change. Signature + expiry come from UriSigner; the uid must match
     * the session user so the link can't act on a different account that
     * happens to be signed in on this browser.
     */
    #[Route('/settings/profile/confirm-email', name: 'app_settings_profile_confirm_email', methods: ['GET'])]
    public function profileConfirmEmail(
        Request $request,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
        UserRepository $users,
        UriSigner $uriSigner,
        MailerInterface $mailer,
        LoggerInterface $logger,
        LocaleSwitcher $localeSwitcher,
        SubscriptionService $subscriptions,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $redirect = $this->redirectToRoute('app_settings_profile');

        if (!$uriSigner->checkRequest($request)) {
            $this->addFlash('error', $translator->trans('settings.profile.confirm_invalid'));

            return $redirect;
        }

        $newEmail = trim((string) $request->query->get('new'));
        if ((int) $request->query->get('uid') !== $user->getId() || $newEmail === '') {
            $this->addFlash('error', $translator->trans('settings.profile.confirm_wrong_account'));

            return $redirect;
        }

        if (strcasecmp($newEmail, (string) $user->getEmail()) === 0) {
            // Double-click / revisit — the change already landed.
            $this->addFlash('success', $translator->trans('settings.profile.confirm_success', [
                'email' => $user->getEmail(),
            ]));

            return $redirect;
        }

        // The link is bound to the address it was ISSUED against: once the
        // account email moves on (a newer link was consumed, or any change
        // landed), every earlier link is dead — no replay window in which a
        // superseded request can flip the address back without a fresh
        // password-gated request (Copilot catch on PR #180).
        if (strcasecmp(trim((string) $request->query->get('old')), (string) $user->getEmail()) !== 0) {
            $this->addFlash('error', $translator->trans('settings.profile.confirm_invalid'));

            return $redirect;
        }

        // Re-checked at consume time: someone else may have registered the
        // address between request and click.
        if ($users->findOneBy(['email' => $newEmail]) !== null) {
            $this->addFlash('error', $translator->trans('settings.profile.email_in_use'));

            return $redirect;
        }

        $oldEmail = (string) $user->getEmail();
        $user->setEmail($newEmail);
        // Clicking the link IS inbox proof for the new address.
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $em->flush();

        $subscriptions->syncCustomerEmail($user);

        // Best-effort heads-up to the OLD inbox — the "if this wasn't you"
        // safety valve. A transport failure must not fail the change.
        try {
            $localeSwitcher->runWithLocale($user->getLocale()->value, function () use (
                $user,
                $oldEmail,
                $newEmail,
                $mailer,
                $translator,
            ): void {
                $mailer->send((new TemplatedEmail())
                    ->to($oldEmail)
                    ->subject($translator->trans('delivery.transactional.email_changed_subject'))
                    ->htmlTemplate('email/email_changed_notice.html.twig')
                    ->context(['user' => $user, 'newEmail' => $newEmail]));
            });
        } catch (\Throwable $e) {
            $logger->warning('Failed to send email-change notice to {email}: {error}', [
                'email' => $oldEmail,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        $this->addFlash('success', $translator->trans('settings.profile.confirm_success', ['email' => $newEmail]));

        return $redirect;
    }

    #[Route('/settings/account', name: 'app_settings_account', methods: ['GET'])]
    public function account(AccountDeletionService $accountDeletion): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->render('settings/account.html.twig', [
            'scheduledPurgeDate' => $accountDeletion->scheduledPurgeDate($user),
        ]);
    }

    #[Route('/settings/account/delete-data', name: 'app_settings_account_delete_data', methods: ['POST'])]
    public function deleteAccountData(
        Request $request,
        AccountDeletionService $accountDeletion,
        TranslatorInterface $translator,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$this->isCsrfTokenValid('account-delete-data', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_settings_account');
        }

        $accountDeletion->purgeUserData($user);
        $this->addFlash('success', $translator->trans('settings.account.fresh_done'));

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/settings/account/delete', name: 'app_settings_account_delete', methods: ['POST'])]
    public function deleteAccount(
        Request $request,
        AccountDeletionService $accountDeletion,
        TranslatorInterface $translator,
        LocaleFormatter $localeFormatter,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$this->isCsrfTokenValid('account-delete', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_settings_account');
        }

        $now = new \DateTimeImmutable();
        $accountDeletion->requestDeletion($user, $now);
        $purgeDate = $accountDeletion->scheduledPurgeDate($user);
        \assert($purgeDate !== null);
        $this->addFlash('success', $translator->trans(
            'settings.account.delete_scheduled',
            // The purge date is a server-frame instant — show the owner's own
            // calendar day, like the banner's user_date.
            ['date' => $localeFormatter->date($purgeDate, 'yMMMMd', new \DateTimeZone($user->getTimezone()))],
        ));

        return $this->redirectToRoute('app_settings_account');
    }

    #[Route('/settings/account/cancel-deletion', name: 'app_settings_account_cancel_deletion', methods: ['POST'])]
    public function cancelAccountDeletion(
        Request $request,
        AccountDeletionService $accountDeletion,
        TranslatorInterface $translator,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$this->isCsrfTokenValid('account-cancel-deletion', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_settings_account');
        }

        $accountDeletion->cancelDeletion($user);
        $this->addFlash('success', $translator->trans('settings.account.cancel_done'));

        return $this->redirectToRoute('app_settings_account');
    }
}

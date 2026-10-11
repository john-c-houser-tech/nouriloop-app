<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\EmailVerifier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Debug\TraceableAuthenticator;
use Symfony\Component\Security\Http\Authenticator\LoginLinkAuthenticator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * A consumed magic link IS proof of inbox control, so every successful
 * login-link sign-in stamps email verification (idempotent). This is also how
 * the welcome email's "Confirm your email & sign in" CTA verifies — it's a
 * login link, so no separate confirmation ceremony exists.
 */
#[AsEventListener]
final class LoginLinkVerificationListener
{
    public function __construct(
        private readonly EmailVerifier $emailVerifier,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        // In dev the profiler wraps authenticators in TraceableAuthenticator —
        // unwrap so the instanceof check behaves the same in every env.
        $authenticator = $event->getAuthenticator();
        if ($authenticator instanceof TraceableAuthenticator) {
            $authenticator = $authenticator->getAuthenticator();
        }
        if (!$authenticator instanceof LoginLinkAuthenticator) {
            return;
        }

        $user = $event->getUser();
        if ($user instanceof User) {
            $this->emailVerifier->markVerified($user);
        }
    }
}
